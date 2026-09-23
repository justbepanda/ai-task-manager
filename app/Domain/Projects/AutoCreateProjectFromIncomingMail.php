<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Support\ProjectNameNormalizer;

/**
 * Optionally create a Project from Redmine subject when matcher found none.
 * Gated by AppSetting::auto_create_projects + confidence threshold.
 */
class AutoCreateProjectFromIncomingMail
{
    public function __construct(
        private readonly RedmineSubjectProjectExtractor $redmineSubjectExtractor,
        private readonly ProjectService $projects,
        private readonly HypothesisMetricsRecorder $metrics,
    ) {}

    /**
     * @return array{project: Project, created: bool, confidence: float}|null
     */
    public function tryCreate(
        Message $message,
        MessageClassification $classification,
        ?int $currentProjectId,
        bool $ambiguous,
    ): ?array {
        if ($currentProjectId !== null || $ambiguous) {
            return null;
        }

        if ($classification === MessageClassification::Ignored) {
            return null;
        }

        $settings = AppSetting::current();
        if (! $settings->auto_create_projects) {
            return null;
        }

        if (! $this->redmineSubjectExtractor->appliesTo($message)) {
            return null;
        }

        $subject = (string) ($message->subject ?? '');
        $name = $this->redmineSubjectExtractor->preferredProjectName($subject);
        if ($name === null || trim($name) === '') {
            return null;
        }

        $confidence = (float) config('classification.redmine_subject_project.match_score', 0.97);
        $threshold = (float) $settings->auto_create_confidence_threshold;
        if ($confidence < $threshold) {
            return null;
        }

        $aliases = $this->redmineSubjectExtractor->extractNameCandidates($subject);
        $existing = $this->findExistingByNameOrAlias($name, $aliases);
        if ($existing !== null) {
            return [
                'project' => $existing,
                'created' => false,
                'confidence' => $confidence,
            ];
        }

        $project = $this->projects->create([
            'name' => $name,
            'status' => ProjectStatus::Active,
            'description' => 'Автосоздан из темы Redmine',
            'keywords' => $aliases === [] ? null : implode("\n", $aliases),
            'technical_sources' => "redmine\n",
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'project_auto_created',
            'status' => 'ok',
            'details' => [
                'project_id' => $project->id,
                'name' => $project->name,
                'confidence' => $confidence,
                'threshold' => $threshold,
                'aliases' => $aliases,
                'source' => 'redmine_subject',
            ],
        ]);

        $this->metrics->record(
            'project_auto_created',
            [
                'project_id' => $project->id,
                'confidence' => $confidence,
            ],
            $message,
            projectId: (int) $project->id,
        );

        return [
            'project' => $project,
            'created' => true,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  list<string>  $aliases
     */
    private function findExistingByNameOrAlias(string $name, array $aliases): ?Project
    {
        $needles = array_values(array_unique(array_filter(
            array_merge([$name], $aliases),
            static fn (string $s): bool => trim($s) !== '',
        )));

        if ($needles === []) {
            return null;
        }

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->get();

        foreach ($projects as $project) {
            $haystacks = [(string) $project->name];
            foreach ($project->matchingRule?->keywords ?? [] as $keyword) {
                $haystacks[] = (string) $keyword;
            }

            foreach ($needles as $needle) {
                foreach ($haystacks as $hay) {
                    if (ProjectNameNormalizer::equals($needle, $hay)) {
                        return $project;
                    }
                }
            }
        }

        return null;
    }
}
