<?php

declare(strict_types=1);

namespace App\Domain\ManagerActions;

use App\Enums\ManagerActionStatus;
use App\Models\ManagerAction;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keep a single pending ManagerAction per conversation / project task:
 * the one tied to the newest message. Older pending items are auto-completed.
 */
class SupersedeStaleConversationManagerActions
{
    /**
     * After creating a pending action: close other pending in the same thread,
     * or close this one if a newer pending already exists.
     *
     * @return int Number of actions marked completed
     */
    public function afterCreated(ManagerAction $created): int
    {
        if ($created->status !== ManagerActionStatus::Pending) {
            return 0;
        }

        return $this->consolidateGroupForMessageId($created->message_id);
    }

    /**
     * Backfill: leave only the newest pending ManagerAction per conversation / task.
     *
     * @return array{superseded: int, groups: int}
     */
    public function cleanupAll(bool $dryRun = false): array
    {
        $pending = ManagerAction::query()
            ->where('status', ManagerActionStatus::Pending)
            ->whereNotNull('message_id')
            ->with('message')
            ->get();

        $groups = $this->groupPendingByThread($pending);
        $superseded = 0;
        $touchedGroups = 0;

        foreach ($groups as $group) {
            if ($group->count() <= 1) {
                continue;
            }

            $touchedGroups++;
            $winner = $this->pickWinner($group);

            foreach ($group as $action) {
                if ($action->id === $winner->id) {
                    continue;
                }

                if (! $dryRun) {
                    $this->markSuperseded($action, $winner);
                }
                $superseded++;
            }
        }

        return [
            'superseded' => $superseded,
            'groups' => $touchedGroups,
        ];
    }

    private function consolidateGroupForMessageId(?int $messageId): int
    {
        if ($messageId === null) {
            return 0;
        }

        $message = Message::query()->find($messageId);
        if ($message === null) {
            return 0;
        }

        $relatedIds = $this->relatedMessageIds($message);
        if ($relatedIds === []) {
            return 0;
        }

        $pending = ManagerAction::query()
            ->where('status', ManagerActionStatus::Pending)
            ->whereIn('message_id', $relatedIds)
            ->with('message')
            ->get();

        if ($pending->count() <= 1) {
            return 0;
        }

        $winner = $this->pickWinner($pending);
        $superseded = 0;

        foreach ($pending as $action) {
            if ($action->id === $winner->id) {
                continue;
            }

            $this->markSuperseded($action, $winner);
            $superseded++;
        }

        return $superseded;
    }

    /**
     * @return list<int>
     */
    private function relatedMessageIds(Message $message): array
    {
        if ($message->conversation_id !== null) {
            return Message::query()
                ->where('conversation_id', $message->conversation_id)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        if ($message->project_task_id !== null) {
            return Message::query()
                ->where('project_task_id', $message->project_task_id)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        return [];
    }

    /**
     * @param  Collection<int, ManagerAction>  $pending
     * @return Collection<string, Collection<int, ManagerAction>>
     */
    private function groupPendingByThread(Collection $pending): Collection
    {
        return $pending
            ->filter(static fn (ManagerAction $action) => $action->message !== null)
            ->groupBy(function (ManagerAction $action): string {
                $message = $action->message;
                assert($message instanceof Message);

                if ($message->conversation_id !== null) {
                    return 'c:'.$message->conversation_id;
                }

                if ($message->project_task_id !== null) {
                    return 't:'.$message->project_task_id;
                }

                return 'solo:'.$action->id;
            });
    }

    /**
     * @param  Collection<int, ManagerAction>  $group
     */
    private function pickWinner(Collection $group): ManagerAction
    {
        return $group
            ->sort(function (ManagerAction $a, ManagerAction $b): int {
                $aTs = $a->message?->received_at?->getTimestamp() ?? 0;
                $bTs = $b->message?->received_at?->getTimestamp() ?? 0;
                if ($aTs !== $bTs) {
                    return $bTs <=> $aTs;
                }

                $aMsg = $a->message_id ?? 0;
                $bMsg = $b->message_id ?? 0;
                if ($aMsg !== $bMsg) {
                    return $bMsg <=> $aMsg;
                }

                return $b->id <=> $a->id;
            })
            ->first();
    }

    private function markSuperseded(ManagerAction $stale, ManagerAction $winner): void
    {
        DB::transaction(function () use ($stale, $winner): void {
            $locked = ManagerAction::query()->lockForUpdate()->find($stale->id);
            if ($locked === null || $locked->status !== ManagerActionStatus::Pending) {
                return;
            }

            $note = sprintf(
                'Автоматически закрыто: актуально действие #%d по более новому письму #%s в той же переписке.',
                $winner->id,
                $winner->message_id !== null ? (string) $winner->message_id : '—',
            );

            $existingNotes = trim((string) ($locked->manager_notes ?? ''));
            $locked->fill([
                'status' => ManagerActionStatus::Completed,
                'manager_notes' => $existingNotes !== '' ? $existingNotes."\n".$note : $note,
                'decided_at' => now(),
            ]);
            $locked->save();
        });
    }
}
