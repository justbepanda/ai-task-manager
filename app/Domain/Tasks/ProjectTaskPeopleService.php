<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Executors\EnsureExecutorManagerRole;
use App\Models\Executor;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;

/**
 * Manual assign/replace of task manager and executors (Orchid task card).
 */
class ProjectTaskPeopleService
{
    public function __construct(
        private readonly EnsureExecutorManagerRole $ensureManagerRole,
    ) {}

    /**
     * @param  list<int|string>|null  $executorIds
     */
    public function update(ProjectTask $task, ?int $managerExecutorId, ?array $executorIds = null): ProjectTask
    {
        return DB::transaction(function () use ($task, $managerExecutorId, $executorIds): ProjectTask {
            $task->manager_executor_id = $managerExecutorId;
            $task->save();

            if ($managerExecutorId !== null) {
                $manager = Executor::query()->find($managerExecutorId);
                if ($manager !== null) {
                    $this->ensureManagerRole->handle($manager);
                    $task->executors()->syncWithoutDetaching([$managerExecutorId]);
                    $task->project?->executors()->syncWithoutDetaching([$managerExecutorId]);
                }
            }

            if ($executorIds !== null) {
                $ids = array_values(array_unique(array_filter(array_map(
                    static fn (mixed $id): int => (int) $id,
                    $executorIds,
                ), static fn (int $id): bool => $id > 0)));

                if ($managerExecutorId !== null && ! in_array($managerExecutorId, $ids, true)) {
                    $ids[] = $managerExecutorId;
                }

                $task->executors()->sync($ids);
                if ($task->project !== null && $ids !== []) {
                    $task->project->executors()->syncWithoutDetaching($ids);
                }
            }

            return $task->fresh(['managerExecutor', 'executors', 'project']) ?? $task;
        });
    }
}
