<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;

/**
 * Backfill manager/executors for existing projects (admin / artisan).
 * Uses latest suitable message per project: Redmine rules first, then LLM.
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
     *     details: list<array{project_id: int, project_name: string, message_id: int, manager_id: ?int, assignee_ids: list<int>, applied: bool}>
     * }
     */
    public function handle(bool $onlyMissingManager = true, int $limit = 50, bool $dryRun = false): array
    {
        $limit = max(1, min(500, $limit));

        $query = Project::query()
            ->where('status', ProjectStatus::Active)
            ->orderBy('id');

        if ($onlyMissingManager) {
            $query->whereNull('manager_executor_id');
        }

        $projects = $query->limit($limit)->get();

        $scanned = 0;
        $touched = 0;
        $skippedNoMessage = 0;
        $details = [];

        foreach ($projects as $project) {
            $scanned++;

            $message = Message::query()
                ->where('project_id', $project->id)
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
                $skippedNoMessage++;
                continue;
            }

            if ($dryRun) {
                $details[] = [
                    'project_id' => (int) $project->id,
                    'project_name' => (string) $project->name,
                    'message_id' => (int) $message->id,
                    'manager_id' => null,
                    'assignee_ids' => [],
                    'applied' => false,
                ];
                $touched++;
                continue;
            }

            $redmine = $this->applyRedminePeople->handle($message, $project);
            $project->refresh();

            $llm = ['manager_id' => null, 'assignee_ids' => [], 'applied' => false];
            $redmineComplete = $redmine['manager_id'] !== null && $redmine['assignee_id'] !== null;
            if (! ($redmineComplete && $project->manager_executor_id !== null)) {
                $llm = $this->applyPeopleViaLlm->handle($message, $project);
                $project->refresh();
            }

            $applied = $redmine['applied'] || $llm['applied'];
            if ($applied) {
                $touched++;
            }

            $details[] = [
                'project_id' => (int) $project->id,
                'project_name' => (string) $project->name,
                'message_id' => (int) $message->id,
                'manager_id' => $project->manager_executor_id !== null
                    ? (int) $project->manager_executor_id
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
