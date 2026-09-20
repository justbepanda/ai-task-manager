<?php

namespace App\Jobs;

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

    public int $tries = 3;

    public function __construct(
        public int $messageId,
    ) {}

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

        $analyzeMessage->handle($message);
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message === null) {
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
            ],
        ]);

        Log::error('AnalyzeMessageJob failed', [
            'message_id' => $this->messageId,
            'error' => $safe,
        ]);
    }
}
