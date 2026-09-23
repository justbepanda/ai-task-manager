<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Executors\EnsureExecutorManagerRole;
use App\Domain\Executors\FindOrCreateExecutorFromDisplayName;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;

/**
 * From Redmine mail body: Автор → project manager, Назначена → project executor.
 */
class ApplyRedminePeopleToProject
{
    public function __construct(
        private readonly RedmineSubjectProjectExtractor $subjectExtractor,
        private readonly RedmineMailPeopleExtractor $peopleExtractor,
        private readonly FindOrCreateExecutorFromDisplayName $findOrCreateExecutor,
        private readonly EnsureExecutorManagerRole $ensureManagerRole,
    ) {}

    /**
     * @return array{manager_id: ?int, assignee_id: ?int, applied: bool}
     */
    public function handle(Message $message, Project $project): array
    {
        if (! $this->subjectExtractor->appliesTo($message)) {
            return ['manager_id' => null, 'assignee_id' => null, 'applied' => false];
        }

        $extracted = $this->peopleExtractor->extract(
            (string) ($message->subject ?? ''),
            (string) ($message->body ?? ''),
        );

        $managerId = null;
        $assigneeId = null;
        $changed = false;

        if ($extracted['author'] !== null) {
            $result = $this->findOrCreateExecutor->handle($extracted['author']);
            $manager = $result['executor'];
            $managerId = (int) $manager->id;

            $project->executors()->syncWithoutDetaching([$managerId]);

            if ($project->manager_executor_id === null) {
                $project->manager_executor_id = $managerId;
                $project->save();
                $changed = true;
            }

            if ($this->ensureManagerRole->handle($manager->fresh() ?? $manager)) {
                $changed = true;
            }
        }

        if ($extracted['assignee'] !== null) {
            $result = $this->findOrCreateExecutor->handle($extracted['assignee']);
            $assignee = $result['executor'];
            $assigneeId = (int) $assignee->id;
            $project->executors()->syncWithoutDetaching([$assigneeId]);
            $changed = true;
        }

        if ($changed || $managerId !== null || $assigneeId !== null) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'redmine_people_applied',
                'status' => 'ok',
                'details' => [
                    'project_id' => $project->id,
                    'author' => $extracted['author'],
                    'assignee' => $extracted['assignee'],
                    'manager_executor_id' => $project->manager_executor_id,
                    'assignee_executor_id' => $assigneeId,
                ],
            ]);
        }

        return [
            'manager_id' => $managerId,
            'assignee_id' => $assigneeId,
            'applied' => $changed || $managerId !== null || $assigneeId !== null,
        ];
    }
}
