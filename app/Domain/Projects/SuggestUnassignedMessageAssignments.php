<?php

namespace App\Domain\Projects;

use App\Domain\Projects\DTO\AssignmentBoard;
use App\Domain\Projects\DTO\AssignmentGroup;
use App\Domain\Projects\DTO\AssignmentSuggestion;
use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;

/**
 * Deterministic suggestions for attaching unassigned messages to projects (no LLM).
 */
class SuggestUnassignedMessageAssignments
{
    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly ProjectMatcher $matcher,
        private readonly RedmineSubjectProjectExtractor $redmineSubjectExtractor,
    ) {}

    public function build(int $limit = self::DEFAULT_LIMIT): AssignmentBoard
    {
        $threshold = (float) config('classification.project_match_suggest_threshold', 0.55);
        $margin = (float) config('classification.project_match_ambiguous_margin', 0.1);

        $messages = Message::query()
            ->whereNull('project_id')
            ->where(function ($q): void {
                $q->whereNull('classification')
                    ->orWhere('classification', '!=', MessageClassification::Ignored->value);
            })
            ->with('conversation')
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        /** @var array<int, list<AssignmentSuggestion>> $byProject */
        $byProject = [];
        /** @var array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}> $byProposed */
        $byProposed = [];
        $unmatched = [];

        $activeProjects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->get();

        foreach ($messages as $message) {
            if ($this->tryGroupRedmineBySubject($message, $activeProjects, $byProject, $byProposed)) {
                continue;
            }

            $result = $this->matcher->match($message);
            $candidates = $result->candidates;

            if ($candidates === []) {
                $unmatched[] = $message;

                continue;
            }

            $top = $candidates[0];
            $second = $candidates[1] ?? null;

            $targetProjectId = null;
            $ambiguous = false;

            if ($result->projectId !== null) {
                $targetProjectId = $result->projectId;
                $ambiguous = false;
            } elseif ($top->score >= $threshold) {
                $clearlyTops = $second === null || ($top->score - $second->score) > $margin;
                $targetProjectId = $top->projectId;
                $ambiguous = $result->ambiguous || ! $clearlyTops;
            }

            if ($targetProjectId === null) {
                $unmatched[] = $message;

                continue;
            }

            $byProject[$targetProjectId][] = new AssignmentSuggestion(
                message: $message,
                score: $top->score,
                reasons: $top->reasons !== [] ? $top->reasons : $result->reasons,
                ambiguous: $ambiguous,
            );
        }

        $groups = $this->buildExistingGroups($byProject);
        $groups = array_merge($groups, $this->buildProposedGroups($byProposed));

        usort(
            $groups,
            static function (AssignmentGroup $a, AssignmentGroup $b): int {
                if ($a->isNew !== $b->isNew) {
                    return $a->isNew ? 1 : -1;
                }

                return strcasecmp($a->title, $b->title);
            },
        );

        return new AssignmentBoard(groups: $groups, unmatched: $unmatched);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Project>  $activeProjects
     * @param  array<int, list<AssignmentSuggestion>>  $byProject
     * @param  array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}>  $byProposed
     */
    private function tryGroupRedmineBySubject(
        Message $message,
        $activeProjects,
        array &$byProject,
        array &$byProposed,
    ): bool {
        if (! $this->redmineSubjectExtractor->appliesTo($message)) {
            return false;
        }

        $subject = (string) ($message->subject ?? '');
        $candidates = $this->redmineSubjectExtractor->extractNameCandidates($subject);
        $preferred = $this->redmineSubjectExtractor->preferredProjectName($subject);

        if ($candidates === [] || $preferred === null) {
            return false;
        }

        $matchedProject = null;
        $matchedAlias = null;
        foreach ($activeProjects as $project) {
            $hit = $this->redmineSubjectExtractor->matchProjectName((string) $project->name, $candidates);
            if ($hit === null) {
                foreach ($project->matchingRule?->keywords ?? [] as $keyword) {
                    $keyword = trim((string) $keyword);
                    if ($keyword === '') {
                        continue;
                    }
                    $hit = $this->redmineSubjectExtractor->matchProjectName($keyword, $candidates);
                    if ($hit !== null) {
                        break;
                    }
                }
            }

            if ($hit !== null) {
                $matchedProject = $project;
                $matchedAlias = $hit;
                break;
            }
        }

        $suggestion = new AssignmentSuggestion(
            message: $message,
            score: (float) config('classification.redmine_subject_project.match_score', 0.97),
            reasons: [
                'redmine_subject:'.($matchedAlias ?? $preferred),
            ],
            ambiguous: false,
        );

        if ($matchedProject !== null) {
            $byProject[(int) $matchedProject->id][] = $suggestion;

            return true;
        }

        $key = mb_strtolower($preferred);
        if (! isset($byProposed[$key])) {
            $byProposed[$key] = [
                'name' => $preferred,
                'aliases' => $candidates,
                'suggestions' => [],
            ];
        } else {
            foreach ($candidates as $alias) {
                if (! in_array($alias, $byProposed[$key]['aliases'], true)) {
                    $byProposed[$key]['aliases'][] = $alias;
                }
            }
        }

        $byProposed[$key]['suggestions'][] = $suggestion;

        return true;
    }

    /**
     * @param  array<int, list<AssignmentSuggestion>>  $byProject
     * @return list<AssignmentGroup>
     */
    private function buildExistingGroups(array $byProject): array
    {
        if ($byProject === []) {
            return [];
        }

        $projects = Project::query()
            ->whereIn('id', array_keys($byProject))
            ->get()
            ->keyBy('id');

        $existingCounts = Message::query()
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->whereIn('project_id', array_keys($byProject))
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $groups = [];
        foreach ($byProject as $projectId => $suggestions) {
            $project = $projects->get($projectId);
            if ($project === null) {
                continue;
            }

            $groups[] = new AssignmentGroup(
                project: $project,
                title: (string) $project->name,
                isNew: false,
                existingCount: (int) ($existingCounts[$projectId] ?? 0),
                suggestions: $suggestions,
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, array{name: string, aliases: list<string>, suggestions: list<AssignmentSuggestion>}>  $byProposed
     * @return list<AssignmentGroup>
     */
    private function buildProposedGroups(array $byProposed): array
    {
        $groups = [];
        foreach ($byProposed as $bucket) {
            $groups[] = new AssignmentGroup(
                project: null,
                title: $bucket['name'].' (новый)',
                isNew: true,
                existingCount: 0,
                suggestions: $bucket['suggestions'],
                suggestedName: $bucket['name'],
                suggestedAliases: $bucket['aliases'],
            );
        }

        return $groups;
    }
}
