<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Support\ProjectNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge duplicate projects (e.g. GigaPlan + Гигаплан) into one canonical project.
 */
class MergeProjects
{
    /**
     * @return array{target: Project, source_id: int, messages_moved: int, tasks_moved: int}
     */
    public function handle(Project $target, Project $source): array
    {
        if ($target->id === $source->id) {
            throw ValidationException::withMessages([
                'project' => 'Нельзя объединить проект сам с собой.',
            ]);
        }

        return DB::transaction(function () use ($target, $source): array {
            $sourceId = (int) $source->id;
            $targetId = (int) $target->id;

            $messagesMoved = Message::query()
                ->where('project_id', $sourceId)
                ->update(['project_id' => $targetId]);

            Conversation::query()
                ->where('project_id', $sourceId)
                ->update(['project_id' => $targetId]);

            Event::query()
                ->where('project_id', $sourceId)
                ->update(['project_id' => $targetId]);

            ManagerAction::query()
                ->where('project_id', $sourceId)
                ->update(['project_id' => $targetId]);

            $tasksMoved = 0;
            foreach (ProjectTask::query()->where('project_id', $sourceId)->get() as $task) {
                $duplicate = ProjectTask::query()
                    ->where('project_id', $targetId)
                    ->where('source', $task->source)
                    ->where('external_key', $task->external_key)
                    ->first();

                if ($duplicate !== null) {
                    Message::query()
                        ->where('project_task_id', $task->id)
                        ->update(['project_task_id' => $duplicate->id]);
                    $task->delete();
                } else {
                    $task->update(['project_id' => $targetId]);
                    $tasksMoved++;
                }
            }

            $this->mergeMatchingKeywords($target, $source);
            $this->mergeRelations($target, $source);

            if ($target->manager_executor_id === null && $source->manager_executor_id !== null) {
                $target->manager_executor_id = $source->manager_executor_id;
                $target->save();
            }

            $source->delete();

            return [
                'target' => $target->fresh(['matchingRule']) ?? $target,
                'source_id' => $sourceId,
                'messages_moved' => $messagesMoved,
                'tasks_moved' => $tasksMoved,
            ];
        });
    }

    /**
     * Find an active project whose name/keywords fold-match any of the needles.
     *
     * @param  list<string>  $needles
     */
    public function findByFoldedNames(array $needles, ?int $exceptProjectId = null): ?Project
    {
        $foldedNeedles = [];
        foreach ($needles as $needle) {
            $folded = ProjectNameNormalizer::fold($needle);
            if ($folded !== '') {
                $foldedNeedles[$folded] = true;
            }
        }
        if ($foldedNeedles === []) {
            return null;
        }

        $query = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule');

        if ($exceptProjectId !== null) {
            $query->whereKeyNot($exceptProjectId);
        }

        foreach ($query->get() as $project) {
            $haystacks = [(string) $project->name];
            foreach ($project->matchingRule?->keywords ?? [] as $keyword) {
                $haystacks[] = (string) $keyword;
            }
            foreach ($haystacks as $hay) {
                $folded = ProjectNameNormalizer::fold($hay);
                if ($folded !== '' && isset($foldedNeedles[$folded])) {
                    return $project;
                }
            }
        }

        return null;
    }

    private function mergeMatchingKeywords(Project $target, Project $source): void
    {
        $target->loadMissing('matchingRule');
        $source->loadMissing('matchingRule');

        $keywords = [];
        foreach (array_merge(
            $target->matchingRule?->keywords ?? [],
            $source->matchingRule?->keywords ?? [],
            [(string) $target->name, (string) $source->name],
        ) as $item) {
            $item = trim((string) $item);
            if ($item !== '' && ! in_array($item, $keywords, true)) {
                $keywords[] = $item;
            }
        }

        $domains = array_values(array_unique(array_merge(
            $target->matchingRule?->domains ?? [],
            $source->matchingRule?->domains ?? [],
        )));
        $technical = array_values(array_unique(array_merge(
            $target->matchingRule?->technical_sources ?? [],
            $source->matchingRule?->technical_sources ?? [],
        )));
        $clientEmails = array_values(array_unique(array_merge(
            $target->matchingRule?->client_emails ?? [],
            $source->matchingRule?->client_emails ?? [],
        )));
        $staffEmails = array_values(array_unique(array_merge(
            $target->matchingRule?->staff_emails ?? [],
            $source->matchingRule?->staff_emails ?? [],
        )));
        $subjectPatterns = array_values(array_unique(array_merge(
            $target->matchingRule?->subject_patterns ?? [],
            $source->matchingRule?->subject_patterns ?? [],
        )));
        $customRules = array_values(array_unique(array_merge(
            $target->matchingRule?->custom_rules ?? [],
            $source->matchingRule?->custom_rules ?? [],
        )));

        $target->matchingRule()->updateOrCreate([], [
            'client_emails' => $clientEmails,
            'staff_emails' => $staffEmails,
            'domains' => $domains,
            'subject_patterns' => $subjectPatterns,
            'keywords' => $keywords,
            'technical_sources' => $technical,
            'custom_rules' => $customRules,
        ]);
    }

    private function mergeRelations(Project $target, Project $source): void
    {
        $executorIds = $source->executors()->pluck('executors.id')->all();
        if ($executorIds !== []) {
            $target->executors()->syncWithoutDetaching($executorIds);
        }

        $companyIds = $source->clientCompanies()->pluck('client_companies.id')->all();
        if ($companyIds !== []) {
            $target->clientCompanies()->syncWithoutDetaching($companyIds);
        }

        $contactIds = $source->clientContacts()->pluck('client_contacts.id')->all();
        if ($contactIds !== []) {
            $target->clientContacts()->syncWithoutDetaching($contactIds);
        }
    }
}
