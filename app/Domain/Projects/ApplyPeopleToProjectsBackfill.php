<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\ProjectTask;

/**
 * Backfill manager/executors on ProjectTask from existing mail (admin / artisan).
 * Uses latest suitable message per task: Redmine rules first, then LLM.
 */
class ApplyPeopleToProjectsBackfill
{
    public function __construct(
        private readonly ApplyRedminePeopleToProject $applyRedminePeople,
        private readonly ApplyPeopleToProjectViaLlm $applyPeopleViaLlm,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     touched: int,
     *     skipped_no_message: int,
     *     details: list<array{
     *         project_id: int,
     *         project_name: string,
     *         project_task_id: int,
     *         message_id: int,
     *         manager_id: ?int,
     *         assignee_ids: list<int>,
     *         applied: bool
     *     }>
     * }
     */
    public function handle(bool $onlyMissingManager = true, int $limit = 50, bool $dryRun = false): array
    {
        $limit = max(1, min(500, $limit));

        $query = ProjectTask::query()
            ->with('project')
            ->whereHas('project', static function ($q): void {
                $q->where('status', ProjectStatus::Active);
            })
            ->orderBy('id');

        if ($onlyMissingManager) {
            $query->whereNull('manager_executor_id');
        }

        $tasks = $query->limit($limit)->get();

        $scanned = 0;
        $touched = 0;
        $skippedNoMessage = 0;
        $details = [];

        foreach ($tasks as $task) {
            $scanned++;
            $project = $task->project;
            if ($project === null) {
                $skippedNoMessage++;
                continue;
            }

            $message = Message::query()
                ->where('project_task_id', $task->id)
                ->where(function ($q): void {
                    $q->whereNull('classification')
                        ->orWhereNotIn('classification', [
                            MessageClassification::Ignored->value,
                            MessageClassification::Newsletter->value,
                            MessageClassification::Personal->value,
                        ]);
                })
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->first();

            if ($message === null) {
                // Fallback: latest project message that can resolve to this task via project_task_id null + same project
                $message = Message::query()
                    ->where('project_id', $project->id)
                    ->where(function ($q) use ($task): void {
                        $q->where('project_task_id', $task->id)
                            ->orWhereNull('project_task_id');
                    })
                    ->where(function ($q): void {
                        $q->whereNull('classification')
                            ->orWhereNotIn('classification', [
                                MessageClassification::Ignored->value,
                                MessageClassification::Newsletter->value,
                                MessageClassification::Personal->value,
                            ]);
                    })
                    ->orderByDesc('received_at')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($message === null) {
                $skippedNoMessage++;
                continue;
            }

            if ($message->project_task_id === null) {
                $message->project_task_id = $task->id;
                if (! $dryRun) {
                    $message->save();
                }
            }

            if ($dryRun) {
                $details[] = [
                    'project_id' => (int) $project->id,
                    'project_name' => (string) $project->name,
                    'project_task_id' => (int) $task->id,
                    'message_id' => (int) $message->id,
                    'manager_id' => null,
                    'assignee_ids' => [],
                    'applied' => false,
                ];
                $touched++;
                continue;
            }

            $redmine = $this->applyRedminePeople->handle($message, $project, $task);
            $task->refresh();

            $llm = ['manager_id' => null, 'assignee_ids' => [], 'applied' => false];
            $redmineComplete = $redmine['manager_id'] !== null && $redmine['assignee_id'] !== null;
            if (! ($redmineComplete && $task->manager_executor_id !== null)) {
                $llm = $this->applyPeopleViaLlm->handle($message, $project, $task);
                $task->refresh();
            }

            $applied = $redmine['applied'] || $llm['applied'];
            if ($applied) {
                $touched++;
            }

            $details[] = [
                'project_id' => (int) $project->id,
                'project_name' => (string) $project->name,
                'project_task_id' => (int) $task->id,
                'message_id' => (int) $message->id,
                'manager_id' => $task->manager_executor_id !== null
                    ? (int) $task->manager_executor_id
                    : ($llm['manager_id'] ?? $redmine['manager_id']),
                'assignee_ids' => $llm['assignee_ids'] !== []
                    ? $llm['assignee_ids']
                    : array_values(array_filter([(int) ($redmine['assignee_id'] ?? 0)])),
                'applied' => $applied,
            ];
        }

        return [
            'scanned' => $scanned,
            'touched' => $touched,
            'skipped_no_message' => $skippedNoMessage,
            'details' => $details,
        ];
    }
}
