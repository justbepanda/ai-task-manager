<?php

namespace App\Jobs;

use App\Enums\MessageProcessingStatus;
use App\Hermes\ProcessIncomingMessage;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry job for Hermes pipeline: classify/match tools → optional LLM → Laravel apply.
 */
class ClassifyMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $messageId,
    ) {}

    public function handle(ProcessIncomingMessage $process): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            Log::warning('ClassifyMessageJob: message not found', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        if ($message->processing_status !== MessageProcessingStatus::Pending
            && $message->classification !== null) {
            Log::info('ClassifyMessageJob: already classified, skipping', [
                'message_id' => $this->messageId,
                'status' => $message->processing_status->value,
            ]);

            return;
        }

        $process->handle($message);
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        $message->update([
            'processing_status' => MessageProcessingStatus::Failed,
            'processing_error' => $exception?->getMessage() ?? 'ClassifyMessageJob failed',
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_pipeline',
            'status' => 'failed',
            'details' => [
                'error' => $exception?->getMessage(),
            ],
        ]);

        Log::error('ClassifyMessageJob failed', [
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
