<?php

namespace App\AI\Services;

use App\AI\DTO\AnalysisResult;
use App\AI\Exceptions\AnalysisException;
use App\AI\Exceptions\AnalysisValidationException;
use App\Domain\Conversations\UpdateConversationAgentMemory;
use App\Domain\Classification\SiteLeadMatcher;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Domain\Projects\RedmineMailPeopleExtractor;
use App\Domain\Projects\UpdateProjectAgentState;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Hermes\Contracts\HermesAgent;
use App\Hermes\Contracts\LlmProvider;
use App\Hermes\DTO\StructuredAgentResult;
use App\Jobs\ApplyEventJob;
use App\Models\Event;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeMessage
{
    public function __construct(
        private readonly HermesAgent $hermes,
        private readonly AnalysisResultValidator $validator,
        private readonly HypothesisMetricsRecorder $metrics,
        private readonly RedmineMailPeopleExtractor $redminePeople,
        private readonly UpdateConversationAgentMemory $agentMemory,
        private readonly UpdateProjectAgentState $projectAgentState,
        private readonly SiteLeadMatcher $siteLeadMatcher,
        private readonly LlmProvider $llm,
    ) {}

    public function handle(Message $message): ?Event
    {
        $rawPayload = null;

        try {
            $proposal = $this->hermes->propose($message);
            $rawPayload = $proposal->payload;
            $result = $this->validator->validate($rawPayload, $proposal->context);
            // Explicit Hermes → Laravel boundary type (same fields as AnalysisResult).
            $result = StructuredAgentResult::fromAnalysisResult($result)->toAnalysisResult();
        } catch (AnalysisValidationException $e) {
            $this->markFailed($message, $e->getMessage(), $e->errorType, $rawPayload);

            return null;
        } catch (AnalysisException $e) {
            if ($e->isRetryable()) {
                $this->markDeferred($message, $e->getMessage(), $e->errorType);

                throw $e;
            }

            $this->markFailed($message, $e->getMessage(), $e->errorType, $rawPayload);

            return null;
        } catch (Throwable $e) {
            $this->markFailed($message, 'Hermes analysis failed', 'unexpected_error', $rawPayload);
            Log::error('AnalyzeMessage unexpected error', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->persist($message, $result, $rawPayload);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function persist(Message $message, AnalysisResult $result, array $rawPayload): Event
    {
        return DB::transaction(function () use ($message, $result, $rawPayload): Event {
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);

            $classification = $message->classification;
            if ($classification === null || $classification === MessageClassification::Unknown) {
                $classification = $result->classification;
            }

            // Never invent a project: only set from validated result when message has none.
            $projectId = $message->project_id ?? $result->projectId;

            $requiresManager = $result->requiresManager;
            if ($this->redminePeople->subjectRequiresFeedback((string) ($message->subject ?? ''))) {
                $requiresManager = true;
            }

            $eventType = $result->eventType;
            $suggestedAction = $result->suggestedAction;
            $summary = $result->summary;
            if ($this->siteLeadMatcher->matches($message)
                || $classification === MessageClassification::SiteLead) {
                $classification = MessageClassification::SiteLead;
                $eventType = EventType::SiteLead;
                $requiresManager = true;
                if ($suggestedAction === ManagerActionType::NoAction) {
                    $suggestedAction = ManagerActionType::Review;
                }
                if (trim($summary) === '' || str_starts_with(mb_strtolower($summary), 'техническ')) {
                    $summary = 'Заявка с сайта: '.trim((string) ($message->subject ?? 'без темы'));
                }
            }

            $message->fill([
                'classification' => $classification,
                'project_id' => $projectId,
                'ai_summary' => $summary,
                'ai_confidence' => $result->confidence,
                'requires_manager' => $requiresManager,
                'processing_status' => MessageProcessingStatus::Analyzed,
                'processing_error' => null,
            ]);
            $message->save();

            $rawFacts = isset($rawPayload['agent_facts']) && is_array($rawPayload['agent_facts'])
                ? $rawPayload['agent_facts']
                : null;
            $memoryResult = new AnalysisResult(
                classification: $result->classification,
                projectId: $result->projectId,
                confidence: $result->confidence,
                eventType: $eventType,
                summary: $summary,
                requiresManager: $requiresManager,
                suggestedAction: $suggestedAction,
                draftReply: $result->draftReply,
                reason: $result->reason,
            );
            $this->agentMemory->apply($message, $memoryResult, $rawFacts);

            if ($projectId !== null) {
                $project = \App\Models\Project::query()->find($projectId);
                if ($project !== null) {
                    $this->projectAgentState->apply($project, $message->fresh() ?? $message, $memoryResult);
                }
            }

            $event = Event::query()->create([
                'message_id' => $message->id,
                'project_id' => $projectId,
                'type' => $eventType,
                'summary' => $summary,
                'confidence' => $result->confidence,
                'payload' => [
                    'classification' => $result->classification->value,
                    'project_id' => $result->projectId,
                    'confidence' => $result->confidence,
                    'event_type' => $eventType->value,
                    'summary' => $summary,
                    'requires_manager' => $requiresManager,
                    'suggested_action' => $suggestedAction->value,
                    'draft_reply' => $result->draftReply,
                    'reason' => $result->reason,
                    'message_classification_kept' => $classification->value,
                ],
                'applied_at' => null,
            ]);

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'ai_analyzed',
                'status' => 'ok',
                'details' => SensitiveDataSanitizer::sanitizeDetails([
                    'event_id' => $event->id,
                    'event_type' => $eventType->value,
                    'confidence' => $result->confidence,
                    'requires_manager' => $requiresManager,
                    'suggested_action' => $suggestedAction->value,
                    'project_id' => $projectId,
                    'ai_model' => $this->resolvedAiModel(),
                    'agent' => 'hermes',
                    'ai_response' => SensitiveDataSanitizer::truncateJson($rawPayload),
                ]),
            ]);

            if ($requiresManager) {
                $this->metrics->record('event_requires_manager', [
                    'event_type' => $eventType->value,
                ], $message, projectId: $projectId);
            }

            if (is_string($result->draftReply) && trim($result->draftReply) !== '') {
                $this->metrics->record('draft_created', [
                    'suggested_action' => $suggestedAction->value,
                ], $message, projectId: $projectId);
            }

            ApplyEventJob::dispatch($event->id);

            return $event;
        });
    }

    /**
     * Soft-fail while waiting for a queue retry (rate limit / transient Gemini errors).
     * Does not set processing_status=failed so the job can be retried.
     */
    private function markDeferred(Message $message, string $safeMessage, string $errorType): void
    {
        $status = $message->processing_status;
        if ($status === MessageProcessingStatus::Failed) {
            $status = MessageProcessingStatus::Classified;
        }

        $message->update([
            'processing_status' => $status === MessageProcessingStatus::Pending
                ? MessageProcessingStatus::Classified
                : $status,
            'processing_error' => $safeMessage,
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'ai_analyzed',
            'status' => 'deferred',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'error_type' => $errorType,
                'error' => $safeMessage,
                'ai_model' => $this->resolvedAiModel(),
                'retry' => true,
            ]),
        ]);

        Log::warning('AnalyzeMessage deferred for queue retry', [
            'message_id' => $message->id,
            'error_type' => $errorType,
            'error' => $safeMessage,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    private function markFailed(Message $message, string $safeMessage, string $errorType, ?array $rawPayload = null): void
    {
        $status = $message->processing_status === MessageProcessingStatus::NeedsManual
            ? MessageProcessingStatus::NeedsManual
            : MessageProcessingStatus::Failed;

        $message->update([
            'processing_status' => $status,
            'processing_error' => $safeMessage,
        ]);

        $details = [
            'error_type' => $errorType,
            'error' => $safeMessage,
            'ai_model' => $this->resolvedAiModel(),
        ];

        if ($rawPayload !== null) {
            $details['ai_response'] = SensitiveDataSanitizer::truncateJson($rawPayload);
        }

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'ai_analyzed',
            'status' => 'failed',
            'details' => SensitiveDataSanitizer::sanitizeDetails($details),
        ]);

        Log::warning('AnalyzeMessage failed', [
            'message_id' => $message->id,
            'error_type' => $errorType,
            'error' => $safeMessage,
        ]);
    }

    private function resolvedAiModel(): string
    {
        return $this->llm->lastModel()
            ?: (string) config('ai.gemini.model', 'unknown');
    }
}
