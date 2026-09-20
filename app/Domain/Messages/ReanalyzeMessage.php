<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\AI\Services\AnalyzeMessage;
use App\Domain\Events\ApplyProjectEventService;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Drop previous Hermes Event/ManagerAction and re-run analysis for a message.
 */
final class ReanalyzeMessage
{
    public function __construct(
        private readonly AnalyzeMessage $analyzeMessage,
        private readonly ApplyProjectEventService $applyProjectEvent,
    ) {}

    public function handle(Message $message, bool $sync = true): void
    {
        DB::transaction(function () use ($message): void {
            ManagerAction::query()->where('message_id', $message->id)->delete();
            Event::query()->where('message_id', $message->id)->delete();

            $message->loadMissing('conversation');
            $projectId = $message->project_id ?? $message->conversation?->project_id;
            $classification = $message->classification;
            if ($classification === null && $projectId !== null) {
                $classification = MessageClassification::Client;
            }

            $message->forceFill([
                'project_id' => $projectId,
                'classification' => $classification,
                'processing_status' => MessageProcessingStatus::Classified,
                'ai_summary' => null,
                'ai_confidence' => null,
                'requires_manager' => null,
                'processing_error' => null,
            ])->save();
        });

        if (! $sync) {
            AnalyzeMessageJob::dispatch($message->id);

            return;
        }

        $fresh = $message->fresh(['conversation', 'project.instruction', 'analysisCorrections']) ?? $message;
        $event = $this->analyzeMessage->handle($fresh);

        if ($event !== null) {
            // AnalyzeMessage also queues ApplyEventJob (after commit); apply inline for CLI reliability.
            $this->applyProjectEvent->handle($event->fresh() ?? $event);
        }
    }
}
