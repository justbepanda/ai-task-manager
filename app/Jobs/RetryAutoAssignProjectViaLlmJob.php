<?php

declare(strict_types=1);

namespace App\Jobs;

use App\AI\Exceptions\AnalysisException;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Hermes\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry LLM project auto-assign after Gemini rate limit / transient errors.
 */
class RetryAutoAssignProjectViaLlmJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
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

    public function handle(ProcessIncomingMessage $process): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        if (in_array($message->processing_status, [
            MessageProcessingStatus::Analyzed,
            MessageProcessingStatus::Applied,
        ], true)) {
            return;
        }

        try {
            $process->continueFromLlmAssign($message, scheduleRetryJob: false);
        } catch (AnalysisException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            Log::warning('RetryAutoAssignProjectViaLlmJob: permanent error', [
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

        if ($message->project_id !== null) {
            return;
        }

        $safe = $exception?->getMessage() ?? 'LLM assign retries exhausted';

        if (in_array($message->classification, [
            MessageClassification::Client,
            MessageClassification::Unknown,
        ], true)) {
            $message->update([
                'processing_status' => MessageProcessingStatus::NeedsManual,
                'processing_error' => $safe,
            ]);
        } else {
            $message->update(['processing_error' => $safe]);
        }

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'project_auto_assigned_llm',
            'status' => 'failed',
            'details' => [
                'error' => $safe,
                'exhausted_retries' => true,
                'attempts' => $this->attempts(),
            ],
        ]);

        Log::error('RetryAutoAssignProjectViaLlmJob failed after retries', [
            'message_id' => $this->messageId,
            'error' => $safe,
        ]);
    }
}
