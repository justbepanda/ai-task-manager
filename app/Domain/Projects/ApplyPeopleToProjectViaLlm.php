<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Executors\EnsureExecutorManagerRole;
use App\Domain\Executors\FindOrCreateExecutorFromDisplayName;
use App\Enums\MessageClassification;
use App\Hermes\Contracts\LlmProvider;
use App\Models\Executor;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\Log;

/**
 * LLM proposes task manager / executors from mail content.
 * Deterministic Redmine parsing runs first; this fills gaps and covers client threads.
 * Never overwrites an existing task manager_executor_id.
 */
class ApplyPeopleToProjectViaLlm
{
    public function __construct(
        private readonly LlmProvider $llm,
        private readonly FindOrCreateExecutorFromDisplayName $findOrCreateExecutor,
        private readonly EnsureExecutorManagerRole $ensureManagerRole,
    ) {}

    /**
     * @return array{manager_id: ?int, assignee_ids: list<int>, applied: bool, task_id: ?int}
     */
    public function handle(Message $message, Project $project, ?ProjectTask $task = null): array
    {
        if ($message->classification === MessageClassification::Ignored
            || $message->classification === MessageClassification::Newsletter
            || $message->classification === MessageClassification::Personal
            || $message->classification === MessageClassification::SiteLead) {
            return ['manager_id' => null, 'assignee_ids' => [], 'applied' => false, 'task_id' => null];
        }

        $task ??= $message->projectTask;
        if ($task === null || (int) $task->project_id !== (int) $project->id) {
            return ['manager_id' => null, 'assignee_ids' => [], 'applied' => false, 'task_id' => null];
        }

        $executors = Executor::query()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name', 'emails', 'position']);

        $bodyChars = (int) config('ai.people_assign.body_chars', 1200);

        try {
            $payload = $this->llm->completeJson(
                $this->systemPrompt(),
                $this->userPrompt($message, $project, $task, $executors, $bodyChars),
            );
        } catch (\Throwable $e) {
            Log::warning('ApplyPeopleToProjectViaLlm: LLM call failed', [
                'message_id' => $message->id,
                'project_id' => $project->id,
                'project_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'llm_people_applied',
                'status' => 'failed',
                'details' => [
                    'error' => $e->getMessage(),
                    'project_task_id' => $task->id,
                ],
            ]);

            return ['manager_id' => null, 'assignee_ids' => [], 'applied' => false, 'task_id' => (int) $task->id];
        }

        $decision = $this->normalizePayload($payload, $executors);
        if ($decision['manager_name'] === null
            && $decision['manager_id'] === null
            && $decision['assignee_names'] === []
            && $decision['assignee_ids'] === []) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'llm_people_applied',
                'status' => 'skipped',
                'details' => [
                    'reason' => $decision['reason'] ?? 'no_people',
                    'confidence' => $decision['confidence'],
                    'project_task_id' => $task->id,
                ],
            ]);

            return ['manager_id' => null, 'assignee_ids' => [], 'applied' => false, 'task_id' => (int) $task->id];
        }

        $managerId = null;
        $assigneeIds = [];
        $changed = false;

        $task->refresh();

        if ($decision['manager_id'] !== null) {
            $managerId = $decision['manager_id'];
        } elseif ($decision['manager_name'] !== null) {
            $managerId = (int) $this->findOrCreateExecutor->handle($decision['manager_name'])['executor']->id;
        }

        if ($managerId !== null) {
            $task->executors()->syncWithoutDetaching([$managerId]);
            $project->executors()->syncWithoutDetaching([$managerId]);
            if ($task->manager_executor_id === null) {
                $task->manager_executor_id = $managerId;
                $task->save();
                $changed = true;
            }
            $manager = Executor::query()->find($managerId);
            if ($manager !== null && $this->ensureManagerRole->handle($manager)) {
                $changed = true;
            }
        }

        foreach ($decision['assignee_ids'] as $id) {
            $assigneeIds[] = $id;
        }
        foreach ($decision['assignee_names'] as $name) {
            $assigneeIds[] = (int) $this->findOrCreateExecutor->handle($name)['executor']->id;
        }
        $assigneeIds = array_values(array_unique(array_filter($assigneeIds)));

        if ($assigneeIds !== []) {
            $task->executors()->syncWithoutDetaching($assigneeIds);
            $project->executors()->syncWithoutDetaching($assigneeIds);
            $changed = true;
        }

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'llm_people_applied',
            'status' => $changed ? 'ok' : 'skipped',
            'details' => [
                'project_id' => $project->id,
                'project_task_id' => $task->id,
                'manager_executor_id' => $task->manager_executor_id,
                'proposed_manager_id' => $managerId,
                'assignee_executor_ids' => $assigneeIds,
                'confidence' => $decision['confidence'],
                'reason' => $decision['reason'],
                'model' => $this->llm->lastModel(),
            ],
        ]);

        return [
            'manager_id' => $managerId,
            'assignee_ids' => $assigneeIds,
            'applied' => $changed,
            'task_id' => (int) $task->id,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты — помощник менеджера проектов. По письму определи менеджера задачи и исполнителей (сотрудников нашей компании).
Верни ТОЛЬКО валидный JSON без markdown.

Схема:
{
  "manager_executor_id": integer or null,
  "manager_name": string or null,
  "assignee_executor_ids": [integer],
  "assignee_names": [string],
  "confidence": float 0..1,
  "reason": string
}

Правила:
- manager — ответственный менеджер по этой задаче/переписке (не клиент).
- assignees — исполнители/разработчики нашей компании, ответственные за работу по задаче.
- id только из списка executors. Иначе null / не включай.
- Если человека нет в списке, но ФИО явно наше (сотрудник) — manager_name / assignee_names.
- Не путай клиентов и внешние контакты с исполнителями.
- Автоответы, рассылки, пустые упоминания — все null / [].
- Не выдумывай людей, которых нет в письме.
- reason — кратко по-русски.
PROMPT;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Executor>  $executors
     */
    private function userPrompt(Message $message, Project $project, ProjectTask $task, $executors, int $bodyChars): string
    {
        $body = (string) ($message->body ?? '');
        if (mb_strlen($body) > $bodyChars) {
            $body = mb_substr($body, 0, $bodyChars).'…';
        }

        $executorRows = $executors->map(static fn (Executor $e): array => [
            'id' => (int) $e->id,
            'name' => $e->fullName(),
            'emails' => $e->emails ?? [],
            'position' => $e->position,
        ])->values()->all();

        return "Контекст:\n".json_encode([
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
            ],
            'task' => [
                'id' => (int) $task->id,
                'title' => (string) ($task->title ?? ''),
                'external_key' => (string) ($task->external_key ?? ''),
                'manager_executor_id' => $task->manager_executor_id,
            ],
            'executors' => $executorRows,
            'message' => [
                'id' => (int) $message->id,
                'sender' => (string) ($message->sender ?? ''),
                'subject' => (string) ($message->subject ?? ''),
                'classification' => $message->classification?->value,
                'body' => $body,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Illuminate\Support\Collection<int, Executor>  $executors
     * @return array{
     *     manager_id: ?int,
     *     manager_name: ?string,
     *     assignee_ids: list<int>,
     *     assignee_names: list<string>,
     *     confidence: float,
     *     reason: ?string
     * }
     */
    private function normalizePayload(array $payload, $executors): array
    {
        $knownIds = $executors->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $knownSet = array_fill_keys($knownIds, true);

        $managerId = isset($payload['manager_executor_id']) ? (int) $payload['manager_executor_id'] : null;
        if ($managerId !== null && $managerId > 0 && ! isset($knownSet[$managerId])) {
            $managerId = null;
        }
        if ($managerId !== null && $managerId <= 0) {
            $managerId = null;
        }

        $managerName = isset($payload['manager_name']) && is_string($payload['manager_name'])
            ? trim($payload['manager_name'])
            : null;
        if ($managerName === '') {
            $managerName = null;
        }

        $assigneeIds = [];
        foreach ($payload['assignee_executor_ids'] ?? [] as $raw) {
            $id = (int) $raw;
            if ($id > 0 && isset($knownSet[$id])) {
                $assigneeIds[] = $id;
            }
        }

        $assigneeNames = [];
        foreach ($payload['assignee_names'] ?? [] as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $name = trim($raw);
            if ($name !== '') {
                $assigneeNames[] = $name;
            }
        }

        return [
            'manager_id' => $managerId,
            'manager_name' => $managerName,
            'assignee_ids' => array_values(array_unique($assigneeIds)),
            'assignee_names' => array_values(array_unique($assigneeNames)),
            'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : 0.0,
            'reason' => isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
        ];
    }
}
