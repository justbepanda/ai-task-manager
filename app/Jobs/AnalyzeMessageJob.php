<?php

namespace App\Jobs;

use App\AI\Exceptions\AnalysisException;
use App\AI\Services\AnalyzeMessage;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /**
     * @var list<int>
     */
    public array $backoff;

    public function __construct(
        public int $messageId,
    ) {
        $this->tries = max(1, (int) config('ai.queue.analyze_tries', 10));
        $backoff = config('ai.queue.analyze_backoff_seconds', [60, 120, 300, 600, 900, 1200, 1800]);
        $this->backoff = is_array($backoff)
            ? array_values(array_map('intval', $backoff))
            : [60, 120, 300, 600, 900];
    }

    public function handle(AnalyzeMessage $analyzeMessage): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            Log::warning('AnalyzeMessageJob: message not found', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        if (in_array($message->processing_status, [
            MessageProcessingStatus::Analyzed,
            MessageProcessingStatus::Applied,
        ], true)) {
            Log::info('AnalyzeMessageJob: already analyzed/applied, skipping', [
                'message_id' => $this->messageId,
                'status' => $message->processing_status->value,
            ]);

            return;
        }

        if (in_array($message->classification, [
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Ignored,
        ], true)) {
            Log::info('AnalyzeMessageJob: skip non-content classification', [
                'message_id' => $this->messageId,
                'classification' => $message->classification->value,
            ]);

            return;
        }

        // Technical: only light Hermes summary when project is known.
        if ($message->classification === MessageClassification::Technical
            && $message->project_id === null) {
            Log::info('AnalyzeMessageJob: skip technical without project', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        try {
            $analyzeMessage->handle($message);
        } catch (AnalysisException $e) {
            if ($e->isRetryable()) {
                Log::warning('AnalyzeMessageJob: retryable Gemini/provider error', [
                    'message_id' => $this->messageId,
                    'attempt' => $this->attempts(),
                    'tries' => $this->tries,
                    'error_type' => $e->errorType,
                ]);

                throw $e;
            }

            // Permanent analysis errors are already persisted by AnalyzeMessage::markFailed.
            Log::warning('AnalyzeMessageJob: permanent analysis error', [
                'message_id' => $this->messageId,
                'error_type' => $e->errorType,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        // Permanent AnalysisException already marked the message inside AnalyzeMessage.
        if ($exception instanceof AnalysisException && ! $exception->isRetryable()) {
            return;
        }

        $safe = $exception?->getMessage() ?? 'AnalyzeMessageJob failed';

        $message->update([
            'processing_status' => MessageProcessingStatus::Failed,
            'processing_error' => $safe,
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'ai_analyzed',
            'status' => 'failed',
            'details' => [
                'error' => $safe,
                'exhausted_retries' => true,
                'attempts' => $this->attempts(),
            ],
        ]);

        Log::error('AnalyzeMessageJob failed after retries', [
            'message_id' => $this->messageId,
            'error' => $safe,
            'attempts' => $this->attempts(),
        ]);
    }
}
