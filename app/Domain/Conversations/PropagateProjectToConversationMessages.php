<?php

declare(strict_types=1);

namespace App\Domain\Conversations;

use App\Domain\Tasks\ResolveOrCreateProjectTask;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Support\Facades\DB;

/**
 * When one message in a conversation gets a project, attach the same project
 * to sibling messages that still have project_id = null (email thread / цепочка).
 */
final class PropagateProjectToConversationMessages
{
    public function __construct(
        private readonly ResolveOrCreateProjectTask $resolveProjectTask,
    ) {}

    /**
     * @return array{updated: int, queued_analyze: int}
     */
    public function handle(Message $source, int $projectId): array
    {
        if ($projectId <= 0 || $source->conversation_id === null) {
            return ['updated' => 0, 'queued_analyze' => 0];
        }

        $toAnalyze = [];
        $updated = 0;

        DB::transaction(function () use ($source, $projectId, &$updated, &$toAnalyze): void {
            $source = Message::query()->lockForUpdate()->with('conversation')->find($source->id);
            if ($source === null || $source->conversation_id === null) {
                return;
            }

            $conversation = $source->conversation;
            if ($conversation === null) {
                return;
            }

            if ($conversation->project_id === null) {
                $conversation->project_id = $projectId;
                $conversation->save();
            } elseif ((int) $conversation->project_id !== $projectId) {
                // Thread already tied to another project — do not rewrite siblings.
                return;
            }

            $siblings = Message::query()
                ->where('conversation_id', $source->conversation_id)
                ->whereNull('project_id')
                ->where('id', '!=', $source->id)
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $sibling) {
                if ($sibling->classification === MessageClassification::Ignored) {
                    continue;
                }

                $sibling->project_id = $projectId;

                if ($sibling->processing_status === MessageProcessingStatus::NeedsManual) {
                    $sibling->processing_status = MessageProcessingStatus::Classified;
                }

                $sibling->save();
                $updated++;

                ProcessingLog::query()->create([
                    'message_id' => $sibling->id,
                    'step' => 'project_matched',
                    'status' => 'ok',
                    'details' => [
                        'project_id' => $projectId,
                        'reason' => 'conversation_propagate',
                        'source_message_id' => $source->id,
                    ],
                ]);

                $this->resolveProjectTask->handle($sibling->fresh(['conversation']) ?? $sibling);

                $fresh = $sibling->fresh();
                if ($fresh !== null && $this->shouldQueueAnalyze($fresh)) {
                    $toAnalyze[] = $fresh->id;
                }
            }
        });

        foreach (array_values(array_unique($toAnalyze)) as $messageId) {
            AnalyzeMessageJob::dispatch($messageId);
        }

        return [
            'updated' => $updated,
            'queued_analyze' => count(array_unique($toAnalyze)),
        ];
    }

    private function shouldQueueAnalyze(Message $message): bool
    {
        if (in_array($message->processing_status, [
            MessageProcessingStatus::Analyzed,
            MessageProcessingStatus::Applied,
        ], true)) {
            return false;
        }

        if ($message->project_id === null) {
            return false;
        }

        return match ($message->classification) {
            MessageClassification::Client,
            MessageClassification::Unknown => true,
            MessageClassification::Technical => true,
            default => false,
        };
    }
}
