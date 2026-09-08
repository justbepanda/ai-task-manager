<?php

namespace App\Domain\Projects;

use App\Models\Message;
use App\Support\EmailAddress;

/**
 * Extracts project name candidates from Redmine notification subjects.
 *
 * Examples:
 * - [Tekta, Текта, ЛК клиента и брокера, поддержка - Bug #12] … → Tekta, Текта, …
 * - [RBI сайт 2025 - New #99143] (Обратная связь) → RBI сайт 2025
 */
class RedmineSubjectProjectExtractor
{
    public function appliesTo(Message $message): bool
    {
        $senderEmail = EmailAddress::extract($message->sender);
        if ($senderEmail === null) {
            return false;
        }

        foreach ($this->configuredSenders() as $configured) {
            if ($senderEmail === $configured) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preferred new project title: first comma alias, else full name before tracker.
     */
    public function preferredProjectName(string $subject): ?string
    {
        $bracket = $this->extractBracketContent($subject);
        if ($bracket === null) {
            return null;
        }

        $projectPart = $this->stripTrackerSuffix($bracket);
        if ($projectPart === '') {
            return null;
        }

        $aliases = [];
        foreach (preg_split('/\s*,\s*/u', $projectPart) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $aliases[] = $part;
            }
        }

        if ($aliases === []) {
            return null;
        }

        return $aliases[0];
    }

    /**
     * @return list<string>
     */
    public function extractNameCandidates(string $subject): array
    {
        $bracket = $this->extractBracketContent($subject);
        if ($bracket === null) {
            return [];
        }

        $projectPart = $this->stripTrackerSuffix($bracket);
        if ($projectPart === '') {
            return [];
        }

        $candidates = [$projectPart];

        foreach (preg_split('/\s*,\s*/u', $projectPart) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $candidates, true)) {
                $candidates[] = $part;
            }
        }

        return array_values($candidates);
    }

    /**
     * @param  list<string>  $candidates
     */
    public function matchProjectName(string $projectName, array $candidates): ?string
    {
        $normalizedProject = $this->normalize($projectName);
        if ($normalizedProject === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalize($candidate);
            if ($normalizedCandidate === '') {
                continue;
            }

            if ($normalizedProject === $normalizedCandidate) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalize($candidate);
            if (mb_strlen($normalizedCandidate) < 4) {
                continue;
            }

            // Prefix/whole-name only — avoid "поддержка" matching "Redmine / техподдержка".
            if (
                str_starts_with($normalizedProject, $normalizedCandidate)
                || str_starts_with($normalizedCandidate, $normalizedProject)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractBracketContent(string $subject): ?string
    {
        if (preg_match('/\[([^\]]+)\]/u', $subject, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function stripTrackerSuffix(string $bracketContent): string
    {
        // Redmine: "Project Name - Tracker #123" (tracker may be localized / multi-word).
        if (preg_match('/^(.*?)\s+-\s+.+?#\d+\s*$/u', $bracketContent, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($bracketContent);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @return list<string>
     */
    private function configuredSenders(): array
    {
        $senders = config('classification.redmine_subject_project.senders', ['redmine@kelnik.pro']);
        if (! is_array($senders)) {
            return ['redmine@kelnik.pro'];
        }

        $normalized = [];
        foreach ($senders as $sender) {
            if (! is_string($sender)) {
                continue;
            }
            $email = EmailAddress::extract($sender) ?? strtolower(trim($sender));
            if ($email !== '') {
                $normalized[] = $email;
            }
        }

        return array_values(array_unique($normalized));
    }
}
