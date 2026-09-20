<?php

namespace App\Hermes;

use App\AI\Services\AnalyzeMessage;
use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Domain\Projects\ApplyRedmineFeedbackRequiresManager;
use App\Domain\Projects\ApplyRedminePeopleToProject;
use App\Domain\Projects\AutoAssignProjectViaLlm;
use App\Enums\MessageClassification;
use App\Hermes\DTO\HermesProcessResult;
use App\Models\AppSetting;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Support\SensitiveDataSanitizer;

/**
 * Full Hermes pipeline for an incoming message:
 * tools (classify + project match [+ Redmine auto-create])
 * → optional LLM auto-assign
 * → Redmine people (Автор / Назначена)
 * → optional LLM propose → Laravel apply
 * → Redmine «Обратная связь» → requires_manager.
 */
class ProcessIncomingMessage
{
    public function __construct(
        private readonly ClassifyAndMatchMessage $classifyAndMatch,
        private readonly AutoAssignProjectViaLlm $autoAssignViaLlm,
        private readonly ApplyRedminePeopleToProject $applyRedminePeople,
        private readonly ApplyRedmineFeedbackRequiresManager $applyRedmineFeedback,
        private readonly AnalyzeMessage $analyzeMessage,
    ) {}

    public function handle(Message $message): HermesProcessResult
    {
        $result = $this->classifyAndMatch->handle($message);
        $message = $result['message'];

        // After deterministic rules / Redmine auto-create: optional LLM distribution.
        $llmAssignAttempted = false;
        $llmAssignApplied = false;
        if ($message->project_id === null) {
            $llmAssignAttempted = (bool) AppSetting::current()->auto_assign_projects_llm
                && $message->classification !== MessageClassification::Ignored;
            $assigned = $this->autoAssignViaLlm->tryAssign($message->loadMissing('conversation'));
            if ($assigned !== null) {
                $message = $assigned['message'];
                $llmAssignApplied = true;
            }
        }

        $this->applyPeopleIfPossible($message);

        $needsLlm = $this->needsLlmAnalysis($message);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_pipeline',
            'status' => 'ok',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'classification' => $message->classification?->value,
                'project_id' => $message->project_id,
                'needs_llm' => $needsLlm,
                'llm_assign_attempted' => $llmAssignAttempted,
                'llm_assign_applied' => $llmAssignApplied,
                'stages' => ['classification', 'project_identification', 'context_retrieval'],
            ]),
        ]);

        if (! $needsLlm) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'hermes_llm_skipped',
                'status' => 'ok',
                'details' => [
                    'reason' => 'deterministic_rules_sufficient_or_non_content',
                    'classification' => $message->classification?->value,
                ],
            ]);

            $message = $this->applyRedmineFeedback->handle($message->fresh() ?? $message);

            return new HermesProcessResult(
                message: $message,
                usedLlm: false,
                needsLlm: false,
            );
        }

        $event = $this->analyzeMessage->handle($message->fresh());
        $message = $this->applyRedmineFeedback->handle($message->fresh() ?? $message);

        return new HermesProcessResult(
            message: $message->fresh(['conversation', 'events']) ?? $message,
            usedLlm: true,
            needsLlm: true,
            event: $event,
        );
    }

    private function applyPeopleIfPossible(Message $message): void
    {
        if ($message->project_id === null) {
            return;
        }

        $project = Project::query()->find($message->project_id);
        if ($project === null) {
            return;
        }

        $this->applyRedminePeople->handle($message, $project);
    }

    private function needsLlmAnalysis(Message $message): bool
    {
        $classification = $message->classification;
        if ($classification === null) {
            return false;
        }

        $projectId = $message->project_id;
        $candidates = $message->metadata['project_match']['candidates'] ?? [];
        $hasCandidates = is_array($candidates) && $candidates !== [];

        return match ($classification) {
            MessageClassification::Client,
            MessageClassification::Unknown => $projectId !== null || $hasCandidates,
            MessageClassification::Technical => $projectId !== null,
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Ignored => false,
        };
    }
}
