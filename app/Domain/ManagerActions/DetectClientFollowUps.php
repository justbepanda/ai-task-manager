<?php

declare(strict_types=1);

namespace App\Domain\ManagerActions;

use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Models\ManagerAction;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Create follow_up ManagerActions when the last message in a conversation
 * is a client mail older than N days (no outbound mail in MVP — silence heuristic).
 */
class DetectClientFollowUps
{
    /**
     * @return array{created: int, scanned: int}
     */
    public function handle(?Carbon $now = null): array
    {
        if (! (bool) config('ai.follow_up.enabled', true)) {
            return ['created' => 0, 'scanned' => 0];
        }

        $days = max(1, (int) config('ai.follow_up.silence_days', 3));
        $limit = max(1, (int) config('ai.follow_up.limit_per_run', 20));
        $cutoff = ($now ?? now())->copy()->subDays($days);

        $candidates = Message::query()
            ->where('classification', MessageClassification::Client)
            ->whereNotNull('project_id')
            ->whereNotNull('conversation_id')
            ->where('received_at', '<=', $cutoff)
            ->orderByDesc('received_at')
            ->limit($limit * 5)
            ->get();

        $created = 0;
        $scanned = 0;

        foreach ($candidates as $message) {
            $scanned++;

            if (! $this->isLatestInConversation($message)) {
                continue;
            }

            if ($this->alreadyHasFollowUp($message)) {
                continue;
            }

            ManagerAction::query()->create([
                'project_id' => $message->project_id,
                'event_id' => null,
                'message_id' => $message->id,
                'action_type' => ManagerActionType::FollowUp,
                'status' => ManagerActionStatus::Pending,
                'reason' => sprintf(
                    'Нет реакции на клиентское письмо #%d более %d дн. (тема: %s)',
                    $message->id,
                    $days,
                    mb_substr((string) ($message->subject ?? '—'), 0, 120),
                ),
                'draft_reply' => null,
            ]);

            $created++;

            if ($created >= $limit) {
                break;
            }
        }

        return ['created' => $created, 'scanned' => $scanned];
    }

    private function isLatestInConversation(Message $message): bool
    {
        return ! Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where(function ($q) use ($message): void {
                $q->where('received_at', '>', $message->received_at)
                    ->orWhere(function ($q2) use ($message): void {
                        $q2->where('received_at', $message->received_at)
                            ->where('id', '>', $message->id);
                    });
            })
            ->exists();
    }

    private function alreadyHasFollowUp(Message $message): bool
    {
        return ManagerAction::query()
            ->where('message_id', $message->id)
            ->where('action_type', ManagerActionType::FollowUp)
            ->whereIn('status', [
                ManagerActionStatus::Pending->value,
                ManagerActionStatus::Approved->value,
                ManagerActionStatus::Edited->value,
                ManagerActionStatus::Completed->value,
            ])
            ->exists();
    }
}
