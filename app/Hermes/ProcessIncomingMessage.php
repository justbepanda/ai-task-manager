<?php

namespace App\Hermes;

use App\AI\Exceptions\AnalysisException;
use App\AI\Services\AnalyzeMessage;
use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Domain\Projects\ApplyPeopleToProjectViaLlm;
use App\Domain\Projects\ApplyRedmineFeedbackRequiresManager;
use App\Domain\Projects\ApplyRedminePeopleToProject;
use App\Domain\Projects\AutoAssignProjectViaLlm;
use App\Domain\Tasks\ResolveOrCreateProjectTask;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Hermes\DTO\HermesProcessResult;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\RetryAutoAssignProjectViaLlmJob;
use App\Models\AppSetting;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Full Hermes pipeline for an incoming message:
 * tools (classify + project match [+ Redmine auto-create])
 * → optional LLM auto-assign
 * → Redmine people (Автор / Назначена)
 * → resolve/create ProjectTask
 * → optional LLM propose → Laravel apply
 * → Redmine «Обратная связь» → requires_manager.
 */
class ProcessIncomingMessage
{
    public function __construct(
        private readonly ClassifyAndMatchMessage $classifyAndMatch,
        private readonly AutoAssignProjectViaLlm $autoAssignViaLlm,
        private readonly ApplyRedminePeopleToProject $applyRedminePeople,
        private readonly ApplyPeopleToProjectViaLlm $applyPeopleViaLlm,
        private readonly ApplyRedmineFeedbackRequiresManager $applyRedmineFeedback,
        private readonly ResolveOrCreateProjectTask $resolveProjectTask,
        private readonly AnalyzeMessage $analyzeMessage,
    ) {}

    public function handle(Message $message): HermesProcessResult
    {
        $result = $this->classifyAndMatch->handle($message);

        return $this->continueFromLlmAssign($result['message']);
    }

    /**
     * Resume after classify (or after a deferred LLM-assign retry).
     *
     * @param  bool  $scheduleRetryJob  When true (first pass), enqueue RetryAutoAssignProjectViaLlmJob.
     *                                  When false (inside that job), rethrow so Laravel job backoff applies.
     */
    public function continueFromLlmAssign(Message $message, bool $scheduleRetryJob = true): HermesProcessResult
    {
        $message = $message->fresh(['conversation']) ?? $message;

        $llmAssignAttempted = false;
        $llmAssignApplied = false;

        if ($message->project_id === null) {
            $llmAssignAttempted = (bool) AppSetting::current()->auto_assign_projects_llm
                && $message->classification !== MessageClassification::Ignored;

            if ($llmAssignAttempted) {
                try {
                    $assigned = $this->autoAssignViaLlm->tryAssign($message->loadMissing('conversation'));
                    if ($assigned !== null) {
                        $message = $assigned['message'];
                        $llmAssignApplied = true;
                    }
                } catch (AnalysisException $e) {
                    if (! $e->isRetryable()) {
                        throw $e;
                    }

                    return $this->deferLlmAssign($message, $e, $scheduleRetryJob);
                }
            }
        }

        // LLM finished without a project → only then «ручная обработка».
        if ($llmAssignAttempted && ! $llmAssignApplied && $message->project_id === null) {
            $message = $this->markNeedsManualIfUnassigned($message);
        }

        // Resolve task before people: manager/executors belong to ProjectTask.
        $task = $this->resolveProjectTask->handle($message->fresh(['conversation']) ?? $message);
        $message = $message->fresh(['conversation', 'projectTask']) ?? $message;

        $this->applyPeopleIfPossible($message, $task);

        $message = $message->fresh() ?? $message;

        $needsLlm = $this->needsLlmAnalysis($message);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_pipeline',
            'status' => 'ok',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'classification' => $message->classification?->value,
                'project_id' => $message->project_id,
                'project_task_id' => $task?->id ?? $message->project_task_id,
                'needs_llm' => $needsLlm,
                'llm_assign_attempted' => $llmAssignAttempted,
                'llm_assign_applied' => $llmAssignApplied,
                'stages' => ['classification', 'project_identification', 'task_resolution', 'context_retrieval'],
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

        $event = null;
        try {
            $event = $this->analyzeMessage->handle($message->fresh());
        } catch (AnalysisException $e) {
            if (! $e->isRetryable()) {
                throw $e;
            }

            $delaySeconds = (int) (config('ai.queue.analyze_backoff_seconds.0') ?? 60);
            $delaySeconds = max(30, $delaySeconds);

            AnalyzeMessageJob::dispatch($message->id)->delay(now()->addSeconds($delaySeconds));

            Log::warning('Hermes: LLM analysis deferred to AnalyzeMessageJob', [
                'message_id' => $message->id,
                'error_type' => $e->errorType,
                'delay_seconds' => $delaySeconds,
            ]);

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'hermes_pipeline',
                'status' => 'deferred',
                'details' => SensitiveDataSanitizer::sanitizeDetails([
                    'reason' => 'llm_retry_scheduled',
                    'error_type' => $e->errorType,
                    'error' => $e->getMessage(),
                    'delay_seconds' => $delaySeconds,
                ]),
            ]);
        }

        $message = $this->applyRedmineFeedback->handle($message->fresh() ?? $message);

        return new HermesProcessResult(
            message: $message->fresh(['conversation', 'events']) ?? $message,
            usedLlm: true,
            needsLlm: true,
            event: $event,
        );
    }

    private function deferLlmAssign(
        Message $message,
        AnalysisException $e,
        bool $scheduleRetryJob,
    ): HermesProcessResult {
        $message->update([
            'processing_status' => MessageProcessingStatus::Classified,
            'processing_error' => $e->getMessage(),
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_pipeline',
            'status' => 'deferred',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'reason' => 'llm_assign_retry_scheduled',
                'error_type' => $e->errorType,
                'error' => $e->getMessage(),
                'schedule_retry_job' => $scheduleRetryJob,
            ]),
        ]);

        if (! $scheduleRetryJob) {
            throw $e;
        }

        $delaySeconds = (int) (config('ai.queue.analyze_backoff_seconds.0') ?? 60);
        $delaySeconds = max(30, $delaySeconds);

        RetryAutoAssignProjectViaLlmJob::dispatch($message->id)
            ->delay(now()->addSeconds($delaySeconds));

        Log::warning('Hermes: LLM assign deferred to RetryAutoAssignProjectViaLlmJob', [
            'message_id' => $message->id,
            'error_type' => $e->errorType,
            'delay_seconds' => $delaySeconds,
        ]);

        return new HermesProcessResult(
            message: $message->fresh(['conversation']) ?? $message,
            usedLlm: true,
            needsLlm: true,
        );
    }

    private function markNeedsManualIfUnassigned(Message $message): Message
    {
        if (! in_array($message->classification, [
            MessageClassification::Client,
            MessageClassification::SiteLead,
            MessageClassification::Unknown,
        ], true)) {
            return $message;
        }

        if ($message->project_id !== null) {
            return $message;
        }

        $message->processing_status = MessageProcessingStatus::NeedsManual;
        $message->processing_error = null;
        $message->save();

        return $message->fresh() ?? $message;
    }

    private function applyPeopleIfPossible(Message $message, ?\App\Models\ProjectTask $task = null): void
    {
        if ($message->project_id === null) {
            return;
        }

        $project = Project::query()->find($message->project_id);
        if ($project === null) {
            return;
        }

        $task ??= $message->projectTask;
        if ($task === null) {
            return;
        }

        $redmine = $this->applyRedminePeople->handle($message, $project, $task);
        $task->refresh();

        // LLM fills gaps: non-Redmine mail, or Redmine without both author+assignee / without manager.
        $redmineComplete = $redmine['manager_id'] !== null && $redmine['assignee_id'] !== null;
        if ($redmineComplete && $task->manager_executor_id !== null) {
            return;
        }

        $this->applyPeopleViaLlm->handle($message, $project, $task);
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
            MessageClassification::SiteLead,
            MessageClassification::Unknown => $projectId !== null || $hasCandidates,
            MessageClassification::Technical => $projectId !== null,
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Ignored => false,
        };
    }
}
