<?php

namespace App\AI\Services;

use App\AI\Contracts\AiAnalyzer;
use App\AI\DTO\AnalysisResult;
use App\AI\Exceptions\AnalysisException;
use App\AI\Exceptions\AnalysisValidationException;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
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
        private readonly BuildMessageContext $buildMessageContext,
        private readonly AiAnalyzer $analyzer,
        private readonly AnalysisResultValidator $validator,
        private readonly HypothesisMetricsRecorder $metrics,
    ) {}

    public function handle(Message $message): ?Event
    {
        $context = $this->buildMessageContext->handle($message);
        $rawPayload = null;

        try {
            $rawPayload = $this->analyzer->analyze($context);
            $result = $this->validator->validate($rawPayload, $context);
        } catch (AnalysisValidationException $e) {
            $this->markFailed($message, $e->getMessage(), $e->errorType, $rawPayload);

            return null;
        } catch (AnalysisException $e) {
            $this->markFailed($message, $e->getMessage(), $e->errorType, $rawPayload);

            return null;
        } catch (Throwable $e) {
            $this->markFailed($message, 'AI analysis failed', 'unexpected_error', $rawPayload);
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

            $message->fill([
                'classification' => $classification,
                'project_id' => $projectId,
                'ai_summary' => $result->summary,
                'ai_confidence' => $result->confidence,
                'requires_manager' => $result->requiresManager,
                'processing_status' => MessageProcessingStatus::Analyzed,
                'processing_error' => null,
            ]);
            $message->save();

            $event = Event::query()->create([
                'message_id' => $message->id,
                'project_id' => $projectId,
                'type' => $result->eventType,
                'summary' => $result->summary,
                'confidence' => $result->confidence,
                'payload' => [
                    'classification' => $result->classification->value,
                    'project_id' => $result->projectId,
                    'confidence' => $result->confidence,
                    'event_type' => $result->eventType->value,
                    'summary' => $result->summary,
                    'requires_manager' => $result->requiresManager,
                    'suggested_action' => $result->suggestedAction->value,
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
                    'event_type' => $result->eventType->value,
                    'confidence' => $result->confidence,
                    'requires_manager' => $result->requiresManager,
                    'suggested_action' => $result->suggestedAction->value,
                    'project_id' => $projectId,
                    'ai_model' => (string) config('ai.gemini.model', 'unknown'),
                    'ai_response' => SensitiveDataSanitizer::truncateJson($rawPayload),
                ]),
            ]);

            if ($result->requiresManager) {
                $this->metrics->record('event_requires_manager', [
                    'event_type' => $result->eventType->value,
                ], $message, projectId: $projectId);
            }

            if (is_string($result->draftReply) && trim($result->draftReply) !== '') {
                $this->metrics->record('draft_created', [
                    'suggested_action' => $result->suggestedAction->value,
                ], $message, projectId: $projectId);
            }

            ApplyEventJob::dispatch($event->id);

            return $event;
        });
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
            'ai_model' => (string) config('ai.gemini.model', 'unknown'),
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
}
