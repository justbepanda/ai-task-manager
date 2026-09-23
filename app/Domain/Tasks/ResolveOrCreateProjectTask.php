<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Enums\MessageClassification;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Message;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;

/**
 * Attach message to a ProjectTask (Redmine/Jira key, or email conversation as task).
 */
final class ResolveOrCreateProjectTask
{
    public function __construct(
        private readonly ProjectTaskKeyExtractor $extractor,
    ) {}

    public function handle(Message $message): ?ProjectTask
    {
        if ($message->classification === MessageClassification::Ignored) {
            return null;
        }

        // Empty IMAP fetches must not become tasks (same rule as PersistIncomingMessage).
        if (trim((string) $message->sender) === ''
            && trim((string) ($message->subject ?? '')) === ''
            && trim((string) ($message->body ?? '')) === '') {
            return null;
        }

        return DB::transaction(function () use ($message): ?ProjectTask {
            $message = Message::query()->lockForUpdate()->find($message->id);
            if ($message === null) {
                return null;
            }

            $extracted = $this->extractor->extract($message);
            if ($extracted !== null) {
                $task = $this->upsertExternalTask($message, $extracted);
            } else {
                $task = $this->upsertEmailThreadTask($message);
            }

            if ($task === null) {
                return null;
            }

            $dirty = false;
            if ($message->project_task_id !== $task->id) {
                $message->project_task_id = $task->id;
                $dirty = true;
            }
            if ($message->project_id === null && $task->project_id !== null) {
                $message->project_id = $task->project_id;
                $dirty = true;
            }
            if ($dirty) {
                $message->save();
            }

            $task->last_message_at = $message->received_at ?? now();
            if ($task->project_id === null && $message->project_id !== null) {
                $task->project_id = $message->project_id;
            }
            $task->save();

            return $task->fresh();
        });
    }

    /**
     * @param  array{
     *     source: ProjectTaskSource,
     *     external_key: string,
     *     title: string|null,
     *     status: ProjectTaskStatus|null
     * }  $extracted
     */
    private function upsertExternalTask(Message $message, array $extracted): ProjectTask
    {
        $task = ProjectTask::query()->firstOrNew([
            'source' => $extracted['source']->value,
            'external_key' => $extracted['external_key'],
        ]);

        if (! $task->exists) {
            $task->project_id = $message->project_id;
            $task->title = $extracted['title'] ?: ('#'.$extracted['external_key']);
            $task->status = $extracted['status'] ?? ProjectTaskStatus::Open;
        } else {
            if ($task->project_id === null && $message->project_id !== null) {
                $task->project_id = $message->project_id;
            }
            if (is_string($extracted['title']) && $extracted['title'] !== '') {
                $task->title = $extracted['title'];
            }
            if ($extracted['status'] instanceof ProjectTaskStatus) {
                $task->status = $extracted['status'];
            }
        }

        $task->save();

        return $task;
    }

    private function upsertEmailThreadTask(Message $message): ?ProjectTask
    {
        if (in_array($message->classification, [
            MessageClassification::Ignored,
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Technical,
        ], true)) {
            return null;
        }

        $message->loadMissing('conversation');
        $conversation = $message->conversation;
        if ($conversation === null) {
            return null;
        }

        // Client/unknown (and unclassified) mail: one conversation = one task.
        $key = (string) ($conversation->external_thread_id ?: ('conversation:'.$conversation->id));

        $task = ProjectTask::query()->firstOrNew([
            'source' => ProjectTaskSource::EmailThread->value,
            'external_key' => $key,
        ]);

        if (! $task->exists) {
            $task->project_id = $message->project_id ?? $conversation->project_id;
            $task->title = $conversation->subject ?: ($message->subject ?: 'Переписка #'.$conversation->id);
            $task->status = ProjectTaskStatus::Open;
        } elseif ($task->project_id === null) {
            $task->project_id = $message->project_id ?? $conversation->project_id;
        }

        $task->save();

        if ($conversation->project_id === null && $task->project_id !== null) {
            $conversation->project_id = $task->project_id;
            $conversation->save();
        }

        return $task;
    }
}
