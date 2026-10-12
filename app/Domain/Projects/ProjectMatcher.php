<?php

namespace App\Domain\Projects;

use App\Domain\Projects\DTO\MatchResult;
use App\Domain\Projects\DTO\ProjectCandidate;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Support\EmailAddress;

/**
 * Deterministic project candidate matching (AGENTS §7).
 * Never invents a project — ambiguous or weak matches leave project_id null.
 */
class ProjectMatcher
{
    public function __construct(
        private readonly RedmineSubjectProjectExtractor $redmineSubjectExtractor,
    ) {}

    public function match(Message $message): MatchResult
    {
        $scores = [];

        $message->loadMissing('conversation');

        if ($message->conversation?->project_id) {
            $projectId = (int) $message->conversation->project_id;
            $this->addScore($scores, $projectId, 0.98, 'conversation_project');
        }

        $senderEmail = EmailAddress::extract($message->sender);
        $senderDomain = EmailAddress::domain($message->sender);
        $participantEmails = array_values(array_unique(array_merge(
            EmailAddress::extractMany($message->sender),
            EmailAddress::extractMany($message->recipients),
            EmailAddress::extractMany($message->cc),
        )));
        $subject = (string) ($message->subject ?? '');
        $body = (string) ($message->body ?? '');

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->get();

        $this->scoreRedmineSubjectProjects($scores, $message, $subject, $projects);
        $this->scoreAsproSubjectProjects($scores, $message, $subject, $projects);

        $redmineSubjectOnly = $this->redmineSubjectExtractor->appliesTo($message)
            && $this->redmineSubjectExtractor->extractNameCandidates($subject) !== [];

        // For Redmine subject mail, do not fall back to catch-all rules
        // (e.g. "Redmine / техподдержка" via keyword "issue").
        if ($redmineSubjectOnly) {
            $candidates = [];
            foreach ($scores as $projectId => $data) {
                $candidates[] = new ProjectCandidate(
                    projectId: (int) $projectId,
                    score: round(min(1.0, $data['score']), 4),
                    reasons: $data['reasons'],
                );
            }

            usort($candidates, static fn (ProjectCandidate $a, ProjectCandidate $b) => $b->score <=> $a->score);

            return $this->resolve($candidates);
        }

        foreach ($projects as $project) {
            $rule = $project->matchingRule;
            if ($rule === null) {
                continue;
            }

            $projectId = (int) $project->id;
            $clientEmails = EmailAddress::normalizeList($rule->client_emails);
            $staffEmails = EmailAddress::normalizeList($rule->staff_emails);
            $domains = EmailAddress::normalizeList($rule->domains);

            foreach ($participantEmails as $email) {
                if (in_array($email, $clientEmails, true)) {
                    $this->addScore($scores, $projectId, 1.0, 'exact_client_email:'.$email);
                }
                if (in_array($email, $staffEmails, true)) {
                    $this->addScore($scores, $projectId, 0.90, 'exact_staff_email:'.$email);
                }
            }

            if ($senderDomain !== null && in_array($senderDomain, $domains, true)) {
                $this->addScore($scores, $projectId, 0.85, 'sender_domain:'.$senderDomain);
            }

            foreach ($rule->subject_patterns ?? [] as $pattern) {
                if ($this->patternMatches((string) $pattern, $subject)) {
                    $this->addScore($scores, $projectId, 0.80, 'subject_pattern:'.$pattern);
                }
            }

            foreach ($rule->keywords ?? [] as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword === '') {
                    continue;
                }
                if (stripos($subject, $keyword) !== false || stripos($body, $keyword) !== false) {
                    $this->addScore($scores, $projectId, 0.65, 'keyword:'.$keyword);
                }
            }

            foreach (EmailAddress::normalizeList($rule->technical_sources) as $source) {
                if ($source === '') {
                    continue;
                }
                if ($this->technicalSourceMatches($source, $senderEmail, $senderDomain, $subject, $body, $message)) {
                    $this->addScore($scores, $projectId, 0.90, 'technical_source:'.$source);
                }
            }
        }

        $candidates = [];
        foreach ($scores as $projectId => $data) {
            $candidates[] = new ProjectCandidate(
                projectId: (int) $projectId,
                score: round(min(1.0, $data['score']), 4),
                reasons: $data['reasons'],
            );
        }

        usort($candidates, static fn (ProjectCandidate $a, ProjectCandidate $b) => $b->score <=> $a->score);

        return $this->resolve($candidates);
    }

    /**
     * @param  array<int, array{score: float, reasons: list<string>}>  $scores
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    private function scoreRedmineSubjectProjects(array &$scores, Message $message, string $subject, $projects): void
    {
        if (! $this->redmineSubjectExtractor->appliesTo($message)) {
            return;
        }

        $candidates = $this->redmineSubjectExtractor->extractNameCandidates($subject);
        if ($candidates === []) {
            return;
        }

        $score = (float) config('classification.redmine_subject_project.match_score', 0.97);

        foreach ($projects as $project) {
            $matched = $this->redmineSubjectExtractor->matchProjectName((string) $project->name, $candidates);
            if ($matched === null) {
                // Also allow keywords on the project as Redmine aliases.
                $keywords = $project->matchingRule?->keywords ?? [];
                foreach ($keywords as $keyword) {
                    $keyword = trim((string) $keyword);
                    if ($keyword === '') {
                        continue;
                    }
                    if ($this->redmineSubjectExtractor->matchProjectName($keyword, $candidates) !== null) {
                        $matched = $keyword;
                        break;
                    }
                }
            }

            if ($matched === null) {
                continue;
            }

            $this->addScore(
                $scores,
                (int) $project->id,
                $score,
                'redmine_subject:'.$matched,
            );
        }
    }

    /**
     * Aspro.Cloud notifications: match project by name/keyword appearing in subject/body.
     *
     * @param  array<int, array{score: float, reasons: list<string>}>  $scores
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    private function scoreAsproSubjectProjects(array &$scores, Message $message, string $subject, $projects): void
    {
        $sender = mb_strtolower((string) ($message->sender ?? ''));
        $senderEmail = EmailAddress::extract($message->sender) ?? '';
        $isAspro = str_contains($sender, 'aspro')
            || str_contains($senderEmail, 'aspro')
            || str_starts_with(mb_strtolower($subject), 'уведомление')
            || str_contains(mb_strtolower($subject), 'аспро');

        if (! $isAspro) {
            return;
        }

        $haystack = mb_strtolower($subject.' '.(string) ($message->body ?? ''));
        $score = (float) config('classification.aspro_project_match_score', 0.92);

        foreach ($projects as $project) {
            $needles = [(string) $project->name];
            foreach ($project->matchingRule?->keywords ?? [] as $keyword) {
                $needles[] = (string) $keyword;
            }

            foreach ($needles as $needle) {
                $needle = trim($needle);
                if (mb_strlen($needle) < 3) {
                    continue;
                }
                if (mb_stripos($haystack, mb_strtolower($needle)) !== false) {
                    $this->addScore($scores, (int) $project->id, $score, 'aspro_subject:'.$needle);
                    break;
                }
            }
        }
    }

    /**
     * @param  list<ProjectCandidate>  $candidates
     */
    private function resolve(array $candidates): MatchResult
    {
        $threshold = (float) config('classification.project_match_auto_threshold', 0.8);
        $margin = (float) config('classification.project_match_ambiguous_margin', 0.1);

        if ($candidates === []) {
            return new MatchResult(
                projectId: null,
                ambiguous: false,
                candidates: [],
                reasons: ['no_candidates'],
            );
        }

        $top = $candidates[0];
        $second = $candidates[1] ?? null;

        if ($top->score < $threshold) {
            return new MatchResult(
                projectId: null,
                ambiguous: false,
                candidates: $candidates,
                reasons: ['below_threshold:'.$top->score],
            );
        }

        $strongCount = count(array_filter(
            $candidates,
            static fn (ProjectCandidate $c) => $c->score >= $threshold,
        ));

        $withinMargin = $second !== null && ($top->score - $second->score) <= $margin;

        if ($strongCount > 1 || $withinMargin) {
            return new MatchResult(
                projectId: null,
                ambiguous: true,
                candidates: $candidates,
                reasons: [
                    'ambiguous_candidates',
                    'top:'.$top->projectId.':'.$top->score,
                    $second ? 'second:'.$second->projectId.':'.$second->score : 'second:none',
                ],
            );
        }

        return new MatchResult(
            projectId: $top->projectId,
            ambiguous: false,
            candidates: $candidates,
            reasons: array_merge(['matched'], $top->reasons),
        );
    }

    /**
     * @param  array<int, array{score: float, reasons: list<string>}>  $scores
     */
    private function addScore(array &$scores, int $projectId, float $score, string $reason): void
    {
        if (! isset($scores[$projectId])) {
            $scores[$projectId] = ['score' => 0.0, 'reasons' => []];
        }

        // Take max signal per reason type; accumulate capped score.
        $scores[$projectId]['score'] = max($scores[$projectId]['score'], $score);
        if (! in_array($reason, $scores[$projectId]['reasons'], true)) {
            $scores[$projectId]['reasons'][] = $reason;
        }
    }

    private function patternMatches(string $pattern, string $subject): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (strlen($pattern) >= 2 && $pattern[0] === '/' && str_ends_with($pattern, '/')) {
            return @preg_match($pattern, $subject) === 1;
        }

        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/iu';

            return @preg_match($regex, $subject) === 1;
        }

        return stripos($subject, $pattern) !== false;
    }

    private function technicalSourceMatches(
        string $source,
        ?string $senderEmail,
        ?string $senderDomain,
        string $subject,
        string $body,
        Message $message,
    ): bool {
        if ($senderEmail !== null && ($senderEmail === $source || str_contains($senderEmail, $source))) {
            return true;
        }

        if ($senderDomain !== null && str_contains($senderDomain, $source)) {
            return true;
        }

        if (stripos($subject, $source) !== false || stripos($body, $source) !== false) {
            return true;
        }

        foreach ($message->headers ?? [] as $value) {
            if (is_string($value) && stripos($value, $source) !== false) {
                return true;
            }
        }

        return false;
    }
}
