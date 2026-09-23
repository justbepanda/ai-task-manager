<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLogRecorder;
use App\Domain\Projects\MergeProjects;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\ProjectNameNormalizer;
use Illuminate\Console\Command;

/**
 * Merge active projects whose names fold to the same key (GigaPlan ≈ Гигаплан).
 * Keeps the project with more messages (tie → lower id).
 */
class MergeDuplicateProjectsCommand extends Command
{
    protected $signature = 'projects:merge-duplicates
                            {--dry-run : Show pairs without merging}';

    protected $description = 'Merge duplicate projects matched by Latin/Cyrillic name fold';

    public function handle(MergeProjects $mergeProjects, ActivityLogRecorder $activity): int
    {
        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->withCount('messages')
            ->with('matchingRule')
            ->orderBy('id')
            ->get();

        /** @var array<string, list<Project>> $groups */
        $groups = [];
        foreach ($projects as $project) {
            $key = ProjectNameNormalizer::fold((string) $project->name);
            if ($key === '' || mb_strlen($key) < 4) {
                continue;
            }
            $groups[$key][] = $project;
        }

        $pairs = [];
        foreach ($groups as $bucket) {
            $unique = [];
            foreach ($bucket as $project) {
                $unique[(int) $project->id] = $project;
            }
            if (count($unique) < 2) {
                continue;
            }
            $pairs[] = array_values($unique);
        }

        if ($pairs === []) {
            $this->info('No duplicate project pairs found.');

            return self::SUCCESS;
        }

        $merged = 0;
        foreach ($pairs as $group) {
            usort($group, static function (Project $a, Project $b): int {
                $cmp = ($b->messages_count ?? 0) <=> ($a->messages_count ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a->id <=> $b->id;
            });

            $target = $group[0];
            foreach (array_slice($group, 1) as $source) {
                $this->line(sprintf(
                    '  %s ← %s (fold match, msgs %d←%d)',
                    $target->name,
                    $source->name,
                    $target->messages_count ?? 0,
                    $source->messages_count ?? 0,
                ));

                if ($this->option('dry-run')) {
                    continue;
                }

                $result = $mergeProjects->handle($target, $source);
                $target = $result['target'];
                $merged++;
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry-run: '.count($pairs).' pair group(s).'
            : "Merged {$merged} duplicate project(s).");

        if (! $this->option('dry-run') && $merged > 0) {
            $activity->record(
                category: 'projects',
                action: 'merge_duplicates',
                status: 'ok',
                summary: "Объединены дубликаты проектов: {$merged}",
                details: ['merged' => $merged, 'source' => 'artisan'],
            );
        }

        return self::SUCCESS;
    }
}
