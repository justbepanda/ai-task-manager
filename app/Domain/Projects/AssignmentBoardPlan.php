<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Projects\DTO\AssignmentBoard;
use App\Domain\Projects\DTO\AssignmentGroup;
use App\Domain\Projects\DTO\AssignmentSuggestion;
use App\Models\Message;
use App\Models\Project;

/**
 * Session-safe snapshot of {@see AssignmentBoard} (message/project ids only).
 */
final class AssignmentBoardPlan
{
    public const SESSION_KEY = 'assign_llm_plan';

    /**
     * @return array{
     *     groups: list<array{
     *         project_id: int|null,
     *         title: string,
     *         is_new: bool,
     *         existing_count: int,
     *         suggested_name: string|null,
     *         suggested_aliases: list<string>,
     *         suggestions: list<array{message_id: int, score: float, reasons: list<string>, ambiguous: bool}>
     *     }>,
     *     unmatched_ids: list<int>
     * }
     */
    public static function serialize(AssignmentBoard $board): array
    {
        $groups = [];
        foreach ($board->groups as $group) {
            $suggestions = [];
            foreach ($group->suggestions as $suggestion) {
                $suggestions[] = [
                    'message_id' => (int) $suggestion->message->id,
                    'score' => $suggestion->score,
                    'reasons' => array_values($suggestion->reasons),
                    'ambiguous' => $suggestion->ambiguous,
                ];
            }

            $groups[] = [
                'project_id' => $group->project?->id !== null ? (int) $group->project->id : null,
                'title' => $group->title,
                'is_new' => $group->isNew,
                'existing_count' => $group->existingCount,
                'suggested_name' => $group->suggestedName,
                'suggested_aliases' => array_values($group->suggestedAliases),
                'suggestions' => $suggestions,
            ];
        }

        $unmatchedIds = [];
        foreach ($board->unmatched as $message) {
            $unmatchedIds[] = (int) $message->id;
        }

        return [
            'groups' => $groups,
            'unmatched_ids' => $unmatchedIds,
        ];
    }

    /**
     * Remove attached messages from a session plan; drop empty groups.
     * Optionally bump existing_count for the project that received attachments.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<int>  $messageIds
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     unmatched_ids: list<int>
     * }
     */
    public static function withoutMessageIds(
        array $plan,
        array $messageIds,
        ?int $attachedToProjectId = null,
        int $attachedCount = 0,
    ): array {
        $remove = array_fill_keys(
            array_values(array_unique(array_filter(
                array_map('intval', $messageIds),
                static fn (int $id): bool => $id > 0,
            ))),
            true,
        );

        if ($remove === []) {
            return [
                'groups' => is_array($plan['groups'] ?? null) ? $plan['groups'] : [],
                'unmatched_ids' => is_array($plan['unmatched_ids'] ?? null) ? $plan['unmatched_ids'] : [],
            ];
        }

        $groups = [];
        foreach ($plan['groups'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $suggestions = [];
            foreach ($row['suggestions'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['message_id'])) {
                    continue;
                }
                $messageId = (int) $item['message_id'];
                if (isset($remove[$messageId])) {
                    continue;
                }
                $suggestions[] = $item;
            }

            if ($suggestions === []) {
                continue;
            }

            $existingCount = (int) ($row['existing_count'] ?? 0);
            $projectId = isset($row['project_id']) && is_numeric($row['project_id'])
                ? (int) $row['project_id']
                : null;
            if (
                $attachedToProjectId !== null
                && $attachedCount > 0
                && $projectId === $attachedToProjectId
            ) {
                $existingCount += $attachedCount;
            }

            $row['suggestions'] = $suggestions;
            $row['existing_count'] = $existingCount;
            $groups[] = $row;
        }

        $unmatchedIds = [];
        foreach ($plan['unmatched_ids'] ?? [] as $id) {
            if (! is_numeric($id)) {
                continue;
            }
            $messageId = (int) $id;
            if (isset($remove[$messageId])) {
                continue;
            }
            $unmatchedIds[] = $messageId;
        }

        return [
            'groups' => $groups,
            'unmatched_ids' => $unmatchedIds,
        ];
    }

    public static function isEmpty(array $plan): bool
    {
        $groups = $plan['groups'] ?? [];
        $unmatched = $plan['unmatched_ids'] ?? [];

        return (! is_array($groups) || $groups === [])
            && (! is_array($unmatched) || $unmatched === []);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function hydrate(array $plan): AssignmentBoard
    {
        $groupRows = is_array($plan['groups'] ?? null) ? $plan['groups'] : [];
        $unmatchedIds = is_array($plan['unmatched_ids'] ?? null) ? $plan['unmatched_ids'] : [];

        $messageIds = [];
        $projectIds = [];
        foreach ($groupRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['project_id']) && is_numeric($row['project_id'])) {
                $projectIds[] = (int) $row['project_id'];
            }
            foreach ($row['suggestions'] ?? [] as $suggestion) {
                if (is_array($suggestion) && isset($suggestion['message_id'])) {
                    $messageIds[] = (int) $suggestion['message_id'];
                }
            }
        }
        foreach ($unmatchedIds as $id) {
            if (is_numeric($id)) {
                $messageIds[] = (int) $id;
            }
        }

        $messages = Message::query()
            ->whereIn('id', array_values(array_unique($messageIds)))
            ->get()
            ->keyBy('id');

        $projects = Project::query()
            ->whereIn('id', array_values(array_unique($projectIds)))
            ->get()
            ->keyBy('id');

        $groups = [];
        foreach ($groupRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $suggestions = [];
            foreach ($row['suggestions'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['message_id'])) {
                    continue;
                }
                $message = $messages->get((int) $item['message_id']);
                if ($message === null) {
                    continue;
                }
                $reasons = [];
                foreach ($item['reasons'] ?? [] as $reason) {
                    if (is_string($reason) && $reason !== '') {
                        $reasons[] = $reason;
                    }
                }
                $suggestions[] = new AssignmentSuggestion(
                    message: $message,
                    score: is_numeric($item['score'] ?? null) ? (float) $item['score'] : 0.5,
                    reasons: $reasons === [] ? ['llm'] : $reasons,
                    ambiguous: (bool) ($item['ambiguous'] ?? false),
                );
            }

            if ($suggestions === []) {
                continue;
            }

            $isNew = (bool) ($row['is_new'] ?? false);
            $projectId = isset($row['project_id']) && is_numeric($row['project_id'])
                ? (int) $row['project_id']
                : null;
            $project = $projectId !== null ? $projects->get($projectId) : null;

            if (! $isNew && $project === null) {
                continue;
            }

            $suggestedName = isset($row['suggested_name']) && is_string($row['suggested_name'])
                ? trim($row['suggested_name'])
                : null;
            if ($suggestedName === '') {
                $suggestedName = null;
            }

            $aliases = [];
            foreach ($row['suggested_aliases'] ?? [] as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $aliases[] = trim($alias);
                }
            }

            $title = is_string($row['title'] ?? null) && $row['title'] !== ''
                ? $row['title']
                : ($isNew
                    ? (($suggestedName ?? 'Новый проект').' (новый)')
                    : (string) ($project?->name ?? 'Проект'));

            $groups[] = new AssignmentGroup(
                project: $isNew ? null : $project,
                title: $title,
                isNew: $isNew,
                existingCount: (int) ($row['existing_count'] ?? 0),
                suggestions: $suggestions,
                suggestedName: $isNew ? ($suggestedName ?? 'Новый проект') : null,
                suggestedAliases: $aliases,
            );
        }

        $unmatched = [];
        foreach ($unmatchedIds as $id) {
            if (! is_numeric($id)) {
                continue;
            }
            $message = $messages->get((int) $id);
            if ($message !== null) {
                $unmatched[] = $message;
            }
        }

        return new AssignmentBoard(groups: $groups, unmatched: $unmatched);
    }
}
