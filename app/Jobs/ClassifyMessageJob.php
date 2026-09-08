<?php

namespace App\Jobs;

use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Enums\MessageProcessingStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $messageId,
    ) {}

    public function handle(ClassifyAndMatchMessage $classifyAndMatch): void
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

        $result = $classifyAndMatch->handle($message);
        $classifyAndMatch->dispatchAnalyzeIfNeeded(
            $result['message'],
            $result['dispatch_analyze'],
        );
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
            'step' => 'classified',
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
