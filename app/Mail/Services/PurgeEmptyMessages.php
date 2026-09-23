<?php

namespace App\Mail\Services;

use App\Models\Conversation;
use App\Models\MailSyncState;
use App\Models\Message;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeEmptyMessages
{
    /**
     * Delete messages that have no subject, sender, or body (failed IMAP fetches),
     * then remove ProjectTask rows left without any messages.
     *
     * @return array{deleted: int, folders_reset: int, orphan_tasks_deleted: int}
     */
    public function handle(bool $dryRun = false): array
    {
        $query = Message::query()
            ->where(function ($q): void {
                $q->whereNull('subject')->orWhere('subject', '');
            })
            ->where(function ($q): void {
                $q->whereNull('sender')->orWhere('sender', '');
            })
            ->where(function ($q): void {
                $q->whereNull('body')->orWhere('body', '');
            });

        $messages = $query->get(['id', 'conversation_id', 'metadata']);
        $deleted = $messages->count();
        $orphanTasksQuery = ProjectTask::query()->whereDoesntHave('messages');
        $orphanTasksCount = (clone $orphanTasksQuery)->count();

        if ($dryRun) {
            return [
                'deleted' => $deleted,
                'folders_reset' => 0,
                'orphan_tasks_deleted' => $orphanTasksCount,
            ];
        }

        if ($deleted === 0 && $orphanTasksCount === 0) {
            return ['deleted' => 0, 'folders_reset' => 0, 'orphan_tasks_deleted' => 0];
        }

        $folders = [];
        foreach ($messages as $message) {
            $folder = (string) (($message->metadata['folder'] ?? '') ?: '');
            if ($folder !== '') {
                $folders[$folder] = true;
            }
        }

        $conversationIds = $messages->pluck('conversation_id')->filter()->unique()->all();
        $ids = $messages->pluck('id')->all();
        $orphanTasksDeleted = 0;

        DB::transaction(function () use ($ids, $conversationIds, &$orphanTasksDeleted): void {
            if ($ids !== []) {
                Message::query()->whereIn('id', $ids)->delete();
            }

            if ($conversationIds !== []) {
                $orphanIds = Conversation::query()
                    ->whereIn('id', $conversationIds)
                    ->whereDoesntHave('messages')
                    ->pluck('id')
                    ->all();

                if ($orphanIds !== []) {
                    Conversation::query()->whereIn('id', $orphanIds)->delete();
                }
            }

            $orphanTasksDeleted = ProjectTask::query()
                ->whereDoesntHave('messages')
                ->delete();
        });

        $foldersReset = 0;
        foreach (array_keys($folders) as $folder) {
            $updated = MailSyncState::query()
                ->where('folder', $folder)
                ->update([
                    'last_uid' => 0,
                    'uidvalidity' => null,
                ]);
            $foldersReset += $updated > 0 ? 1 : 0;
        }

        Log::info('Purged empty messages', [
            'deleted' => $deleted,
            'folders_reset' => $foldersReset,
            'orphan_tasks_deleted' => $orphanTasksDeleted,
        ]);

        return [
            'deleted' => $deleted,
            'folders_reset' => $foldersReset,
            'orphan_tasks_deleted' => $orphanTasksDeleted,
        ];
    }
}
