<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\AI\Exceptions\AnalysisException;
use App\Domain\Projects\DTO\AssignmentBoard;
use App\Domain\Projects\DTO\AssignmentGroup;
use App\Domain\Projects\DTO\AssignmentSuggestion;
use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Hermes\Contracts\LlmProvider;
use App\Models\Message;
use App\Models\Project;
use App\Support\MailRoutingGuidance;
use Illuminate\Support\Facades\Log;

/**
 * LLM suggestions for attaching unassigned messages to projects.
 * Hermes/LLM only proposes; this class validates and builds the board.
 */
class SuggestUnassignedMessageAssignmentsWithLlm
{
    public function __construct(
        private readonly LlmProvider $llm,
    ) {}

    public function build(?int $limit = null): AssignmentBoard
    {
        $limit ??= (int) config('ai.assign_suggest.limit', 40);
        $bodyChars = (int) config('ai.assign_suggest.body_chars', 400);

        $messages = Message::query()
            ->whereNull('project_id')
            ->where(function ($q): void {
                $q->whereNull('classification')
                    ->orWhere('classification', '!=', MessageClassification::Ignored->value);
            })
            ->orderByDesc('received_at')
            ->limit(max(1, $limit))
            ->get();

        if ($messages->isEmpty()) {
            return new AssignmentBoard(groups: [], unmatched: []);
        }

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->orderBy('name')
            ->get();

        $allowedMessageIds = $messages->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $allowedProjectIds = $projects->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $payload = $this->llm->completeJson(
            $this->systemPrompt(),
            $this->userPrompt($messages, $projects, $bodyChars),
        );

        return $this->buildBoardFromPayload(
            $payload,
            $messages->keyBy('id'),
            $projects->keyBy('id'),
            $allowedMessageIds,
            $allowedProjectIds,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Illuminate\Support\Collection<int, Message>  $messagesById
     * @param  \Illuminate\Support\Collection<int, Project>  $projectsById
     * @param  list<int>  $allowedMessageIds
     * @param  list<int>  $allowedProjectIds
     */
    public function buildBoardFromPayload(
        array $payload,
        $messagesById,
        $projectsById,
        array $allowedMessageIds,
        array $allowedProjectIds,
    ): AssignmentBoard {
        $allowedMessageSet = array_fill_keys($allowedMessageIds, true);
        $allowedProjectSet = array_fill_keys($allowedProjectIds, true);
        $usedMessageIds = [];

        /** @var array<int, list<AssignmentSuggestion>> $byProject */
        $byProject = [];
        /** @var array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}> $byProposed */
        $byProposed = [];

        $groupsRaw = $payload['groups'] ?? null;
        if (! is_array($groupsRaw)) {
            throw new AnalysisException('LLM assign payload must contain groups array', 'validation_error');
        }

        foreach ($groupsRaw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $messageIds = $this->normalizeIdList($row['message_ids'] ?? []);
            if ($messageIds === []) {
                continue;
            }

            $projectId = array_key_exists('project_id', $row) && $row['project_id'] !== null
                ? (is_numeric($row['project_id']) ? (int) $row['project_id'] : null)
                : null;

            $confidence = is_numeric($row['confidence'] ?? null)
                ? max(0.0, min(1.0, (float) $row['confidence']))
                : 0.7;
            $ambiguous = $confidence < 0.75;
            $reason = isset($row['reason']) && is_string($row['reason']) && trim($row['reason']) !== ''
                ? trim($row['reason'])
                : 'llm_assign';

            $validIds = [];
            foreach ($messageIds as $messageId) {
                if (! isset($allowedMessageSet[$messageId]) || isset($usedMessageIds[$messageId])) {
                    continue;
                }
                if (! $messagesById->has($messageId)) {
                    continue;
                }
                $validIds[] = $messageId;
            }

            if ($validIds === []) {
                continue;
            }

            if ($projectId !== null) {
                if (! isset($allowedProjectSet[$projectId]) || ! $projectsById->has($projectId)) {
                    Log::info('LLM assign ignored unknown project_id', [
                        'project_id' => $projectId,
                    ]);

                    continue;
                }

                foreach ($validIds as $messageId) {
                    $usedMessageIds[$messageId] = true;
                    $byProject[$projectId][] = new AssignmentSuggestion(
                        message: $messagesById->get($messageId),
                        score: $confidence,
                        reasons: ['llm:'.$reason],
                        ambiguous: $ambiguous,
                    );
                }

                continue;
            }

            $proposedName = isset($row['proposed_name']) && is_string($row['proposed_name'])
                ? trim($row['proposed_name'])
                : '';
            if ($proposedName === '') {
                continue;
            }

            $aliases = [];
            foreach ($row['proposed_aliases'] ?? [] as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $aliases[] = trim($alias);
                }
            }

            $key = mb_strtolower($proposedName);
            if (! isset($byProposed[$key])) {
                $byProposed[$key] = [
                    'name' => $proposedName,
                    'aliases' => $aliases,
                    'suggestions' => [],
                ];
            } else {
                foreach ($aliases as $alias) {
                    if (! in_array($alias, $byProposed[$key]['aliases'], true)) {
                        $byProposed[$key]['aliases'][] = $alias;
                    }
                }
            }

            foreach ($validIds as $messageId) {
                $usedMessageIds[$messageId] = true;
                $byProposed[$key]['suggestions'][] = new AssignmentSuggestion(
                    message: $messagesById->get($messageId),
                    score: $confidence,
                    reasons: ['llm:'.$reason],
                    ambiguous: $ambiguous,
                );
            }
        }

        $unmatchedIds = $this->normalizeIdList($payload['unmatched_message_ids'] ?? []);
        $unmatched = [];
        $seenUnmatched = [];

        foreach ($unmatchedIds as $messageId) {
            if (! isset($allowedMessageSet[$messageId]) || isset($usedMessageIds[$messageId])) {
                continue;
            }
            $message = $messagesById->get($messageId);
            if ($message === null) {
                continue;
            }
            $usedMessageIds[$messageId] = true;
            $seenUnmatched[$messageId] = true;
            $unmatched[] = $message;
        }

        foreach ($allowedMessageIds as $messageId) {
            if (isset($usedMessageIds[$messageId]) || isset($seenUnmatched[$messageId])) {
                continue;
            }
            $message = $messagesById->get($messageId);
            if ($message !== null) {
                $unmatched[] = $message;
            }
        }

        $groups = $this->buildExistingGroups($byProject, $projectsById);
        $groups = array_merge($groups, $this->buildProposedGroups($byProposed));

        usort(
            $groups,
            static function (AssignmentGroup $a, AssignmentGroup $b): int {
                if ($a->isNew !== $b->isNew) {
                    return $a->isNew ? 1 : -1;
                }

                return strcasecmp($a->title, $b->title);
            },
        );

        return new AssignmentBoard(groups: $groups, unmatched: $unmatched);
    }

    private function systemPrompt(): string
    {
        $base = <<<'PROMPT'
Ты — помощник менеджера проектов. Распредели нераспределённые письма по существующим проектам или предложи создать новый проект.
Верни ТОЛЬКО валидный JSON без markdown.

Схема:
{
  "groups": [
    {
      "project_id": integer or null,
      "proposed_name": string or null,
      "proposed_aliases": [string],
      "message_ids": [integer],
      "confidence": float 0..1,
      "reason": string
    }
  ],
  "unmatched_message_ids": [integer]
}

Правила:
- project_id только из списка projects в запросе. Иначе null.
- Если project_id = null и письмо явно относится к новому проекту — укажи proposed_name (и aliases при необходимости).
- Не выдумывай message_id: только из списка messages.
- Каждое письмо ровно один раз: либо в одной группе, либо в unmatched_message_ids.
- Не привязывай рассылки/личное/шум к проектам без явной связи — в unmatched.
- Для Redmine: имя проекта бери из темы [Name - Tracker #id].
- reason — кратко, на русском.
PROMPT;

        return $base."\n".MailRoutingGuidance::projectAssignBlock();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Message>  $messages
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    private function userPrompt($messages, $projects, int $bodyChars): string
    {
        $projectRows = $projects->map(static function (Project $project): array {
            $rule = $project->matchingRule;

            return [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'client_emails' => $rule?->client_emails ?? [],
                'domains' => $rule?->domains ?? [],
                'keywords' => array_slice($rule?->keywords ?? [], 0, 12),
                'technical_sources' => $rule?->technical_sources ?? [],
            ];
        })->values()->all();

        $messageRows = $messages->map(static function (Message $message) use ($bodyChars): array {
            $body = (string) ($message->body ?? '');
            if (mb_strlen($body) > $bodyChars) {
                $body = mb_substr($body, 0, $bodyChars).'…';
            }

            return [
                'id' => (int) $message->id,
                'sender' => (string) ($message->sender ?? ''),
                'subject' => (string) ($message->subject ?? ''),
                'received_at' => optional($message->received_at)?->toIso8601String(),
                'classification' => $message->classification?->value ?? $message->classification,
                'body' => $body,
            ];
        })->values()->all();

        return "Контекст для распределения:\n".json_encode(
            [
                'projects' => $projectRows,
                'messages' => $messageRows,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  mixed  $raw
     * @return list<int>
     */
    private function normalizeIdList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, list<AssignmentSuggestion>>  $byProject
     * @param  \Illuminate\Support\Collection<int, Project>  $projectsById
     * @return list<AssignmentGroup>
     */
    private function buildExistingGroups(array $byProject, $projectsById): array
    {
        if ($byProject === []) {
            return [];
        }

        $existingCounts = Message::query()
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->whereIn('project_id', array_keys($byProject))
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $groups = [];
        foreach ($byProject as $projectId => $suggestions) {
            $project = $projectsById->get($projectId);
            if ($project === null) {
                continue;
            }

            $groups[] = new AssignmentGroup(
                project: $project,
                title: (string) $project->name,
                isNew: false,
                existingCount: (int) ($existingCounts[$projectId] ?? 0),
                suggestions: $suggestions,
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}>  $byProposed
     * @return list<AssignmentGroup>
     */
    private function buildProposedGroups(array $byProposed): array
    {
        $groups = [];
        foreach ($byProposed as $bucket) {
            $groups[] = new AssignmentGroup(
                project: null,
                title: $bucket['name'].' (новый)',
                isNew: true,
                existingCount: 0,
                suggestions: $bucket['suggestions'],
                suggestedName: $bucket['name'],
                suggestedAliases: $bucket['aliases'],
            );
        }

        return $groups;
    }
}
