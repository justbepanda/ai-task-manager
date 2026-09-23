<?php

namespace App\Hermes;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Hermes tool: propose project assignment groups via LLM (manager confirms in UI).
 */
class SuggestUnassignedMessageAssignmentsWithLlm
{
    public function __construct(
        private readonly LlmProvider $llm,
    ) {}

    public function build(?int $limit = null): AssignmentBoard
    {
        $limit = $limit ?? (int) config('ai.assign_suggest.limit', 40);
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

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        if ($messages->isEmpty()) {
            return new AssignmentBoard(groups: [], unmatched: []);
        }

        $payload = $this->llm->completeJson(
            $this->systemPrompt(),
            $this->userPrompt($messages, $projects, $bodyChars),
        );

        return $this->hydrateBoard($payload, $messages, $projects);
    }

    private function systemPrompt(): string
    {
        $base = <<<'PROMPT'
Ты — Hermes, помощник менеджера проектов. Распредели письма по проектам.
Верни ТОЛЬКО JSON:

{
  "groups": [
    {
      "project_id": 12,
      "message_ids": [1, 2],
      "reason": "кратко почему"
    },
    {
      "new_project_name": "Tekta",
      "message_ids": [5],
      "reason": "проекта нет в списке, предложи название"
    }
  ],
  "unmatched_message_ids": [9]
}

Правила:
- project_id только из списка projects; иначе используй new_project_name или unmatched.
- Не выдумывай message_id — только из списка messages.
- Одно письмо — только в одной группе или unmatched.
- Для Redmine смотри тему: [Имя - Tracker #id] → имя проекта (первый алиас до запятой).
- Не отправляй письма и не меняй данные сам — только предложение.
PROMPT;

        return $base."\n".MailRoutingGuidance::projectAssignBlock();
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  Collection<int, Project>  $projects
     */
    private function userPrompt(Collection $messages, Collection $projects, int $bodyChars): string
    {
        $projectsPayload = $projects->map(static fn (Project $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => Str::limit((string) ($p->description ?? ''), 160),
        ])->values()->all();

        $messagesPayload = $messages->map(static function (Message $m) use ($bodyChars): array {
            return [
                'id' => $m->id,
                'sender' => $m->sender,
                'subject' => $m->subject,
                'body' => Str::limit((string) ($m->body ?? ''), $bodyChars),
                'classification' => $m->classification?->value,
                'received_at' => $m->received_at?->toIso8601String(),
            ];
        })->values()->all();

        return json_encode([
            'projects' => $projectsPayload,
            'messages' => $messagesPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, Message>  $messages
     * @param  Collection<int, Project>  $projects
     */
    private function hydrateBoard(array $payload, Collection $messages, Collection $projects): AssignmentBoard
    {
        $byId = $messages->keyBy('id');
        $projectsById = $projects->keyBy('id');
        $assigned = [];

        /** @var array<int, list<AssignmentSuggestion>> $byProject */
        $byProject = [];
        /** @var array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}> $byProposed */
        $byProposed = [];

        $groups = $payload['groups'] ?? null;
        if (! is_array($groups)) {
            throw new AnalysisException('LLM assign payload missing groups', 'validation_error');
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $messageIds = $group['message_ids'] ?? [];
            if (! is_array($messageIds) || $messageIds === []) {
                continue;
            }

            $reason = is_string($group['reason'] ?? null) ? $group['reason'] : 'llm_suggest';
            $confidence = isset($group['confidence']) ? (float) $group['confidence'] : 0.75;

            $suggestions = [];
            foreach ($messageIds as $rawId) {
                $id = (int) $rawId;
                if ($id <= 0 || isset($assigned[$id]) || ! $byId->has($id)) {
                    continue;
                }
                $assigned[$id] = true;
                $suggestions[] = new AssignmentSuggestion(
                    message: $byId->get($id),
                    score: min(1.0, max(0.0, $confidence)),
                    reasons: ['llm:'.$reason],
                    ambiguous: false,
                );
            }

            if ($suggestions === []) {
                continue;
            }

            $projectId = isset($group['project_id']) ? (int) $group['project_id'] : 0;
            if ($projectId > 0 && $projectsById->has($projectId)) {
                $byProject[$projectId] = array_merge($byProject[$projectId] ?? [], $suggestions);

                continue;
            }

            $newName = trim((string) ($group['new_project_name'] ?? ''));
            if ($newName === '') {
                foreach ($suggestions as $suggestion) {
                    unset($assigned[$suggestion->message->id]);
                }

                continue;
            }

            $key = mb_strtolower($newName);
            if (! isset($byProposed[$key])) {
                $byProposed[$key] = [
                    'name' => $newName,
                    'aliases' => [$newName],
                    'suggestions' => [],
                ];
            }
            $byProposed[$key]['suggestions'] = array_merge(
                $byProposed[$key]['suggestions'],
                $suggestions,
            );
        }

        $unmatched = [];
        $unmatchedIds = $payload['unmatched_message_ids'] ?? [];
        if (is_array($unmatchedIds)) {
            foreach ($unmatchedIds as $rawId) {
                $id = (int) $rawId;
                if ($id > 0 && $byId->has($id) && ! isset($assigned[$id])) {
                    $assigned[$id] = true;
                    $unmatched[] = $byId->get($id);
                }
            }
        }

        foreach ($messages as $message) {
            if (! isset($assigned[$message->id])) {
                $unmatched[] = $message;
            }
        }

        $boardGroups = [];

        if ($byProject !== []) {
            $existingCounts = Message::query()
                ->selectRaw('project_id, COUNT(*) as cnt')
                ->whereIn('project_id', array_keys($byProject))
                ->groupBy('project_id')
                ->pluck('cnt', 'project_id');

            foreach ($byProject as $projectId => $suggestions) {
                $project = $projectsById->get($projectId);
                if ($project === null) {
                    continue;
                }
                $boardGroups[] = new AssignmentGroup(
                    project: $project,
                    title: (string) $project->name,
                    isNew: false,
                    existingCount: (int) ($existingCounts[$projectId] ?? 0),
                    suggestions: $suggestions,
                );
            }
        }

        foreach ($byProposed as $bucket) {
            $boardGroups[] = new AssignmentGroup(
                project: null,
                title: $bucket['name'].' (новый)',
                isNew: true,
                existingCount: 0,
                suggestions: $bucket['suggestions'],
                suggestedName: $bucket['name'],
                suggestedAliases: $bucket['aliases'],
            );
        }

        usort(
            $boardGroups,
            static function (AssignmentGroup $a, AssignmentGroup $b): int {
                if ($a->isNew !== $b->isNew) {
                    return $a->isNew ? 1 : -1;
                }

                return strcasecmp($a->title, $b->title);
            },
        );

        return new AssignmentBoard(groups: $boardGroups, unmatched: $unmatched);
    }
}
