<?php

namespace App\Domain\Projects;

use App\Domain\Messages\MessageManualOverrideService;
use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkAttachMessagesToProject
{
    public function __construct(
        private readonly MessageManualOverrideService $overrides,
        private readonly ProjectService $projects,
        private readonly MergeProjects $mergeProjects,
    ) {}

    /**
     * Attach only currently unassigned messages to a project.
     *
     * @param  list<int>  $messageIds
     */
    public function attach(int $projectId, array $messageIds): int
    {
        if (! Project::query()->whereKey($projectId)->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'Проект не найден.',
            ]);
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $messageIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return 0;
        }

        $attached = 0;
        $toAnalyze = [];

        DB::transaction(function () use ($projectId, $ids, &$attached, &$toAnalyze): void {
            $messages = Message::query()
                ->whereIn('id', $ids)
                ->whereNull('project_id')
                ->lockForUpdate()
                ->get();

            foreach ($messages as $message) {
                $this->overrides->apply($message, ['project_id' => $projectId]);
                $fresh = $message->fresh();
                $attached++;

                if ($fresh !== null && $this->overrides->shouldQueueAnalyze($fresh)) {
                    $toAnalyze[] = $fresh->id;
                }
            }
        });

        foreach ($toAnalyze as $messageId) {
            AnalyzeMessageJob::dispatch($messageId);
        }

        return $attached;
    }

    /**
     * Create an active project (empty rules) and attach unassigned messages.
     *
     * @param  list<int>  $messageIds
     * @param  list<string>  $keywords
     * @return array{project: Project, attached: int}
     */
    public function createProjectAndAttach(string $name, array $messageIds, array $keywords = []): array
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Укажите название проекта.',
            ]);
        }

        $normalizedKeywords = [];
        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }
            $keyword = trim($keyword);
            if ($keyword !== '' && ! in_array($keyword, $normalizedKeywords, true)) {
                $normalizedKeywords[] = $keyword;
            }
        }

        $existing = $this->mergeProjects->findByFoldedNames(array_merge([$name], $normalizedKeywords));
        if ($existing !== null) {
            $attached = $this->attach((int) $existing->id, $messageIds);

            return [
                'project' => $existing,
                'attached' => $attached,
            ];
        }

        $project = $this->projects->create([
            'name' => $name,
            'status' => ProjectStatus::Active,
            'description' => null,
            'instruction_body' => null,
            'keywords' => $normalizedKeywords === [] ? null : implode("\n", $normalizedKeywords),
        ]);

        $attached = $this->attach((int) $project->id, $messageIds);

        return [
            'project' => $project,
            'attached' => $attached,
        ];
    }
}
