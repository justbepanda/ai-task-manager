<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Message;

/**
 * Detects external task identity from mail subject (Redmine / Jira).
 */
final class ProjectTaskKeyExtractor
{
    /**
     * @return array{
     *     source: ProjectTaskSource,
     *     external_key: string,
     *     title: string|null,
     *     status: ProjectTaskStatus|null
     * }|null
     */
    public function extract(Message $message): ?array
    {
        $subject = trim((string) ($message->subject ?? ''));
        if ($subject === '') {
            return null;
        }

        if ($redmine = $this->extractRedmine($subject)) {
            return $redmine;
        }

        if ($jira = $this->extractJira($subject)) {
            return $jira;
        }

        return null;
    }

    /**
     * @return array{
     *     source: ProjectTaskSource,
     *     external_key: string,
     *     title: string|null,
     *     status: ProjectTaskStatus|null
     * }|null
     */
    private function extractRedmine(string $subject): ?array
    {
        // [Project - Bug #99139] (Обратная связь) Title…
        if (preg_match('/\[([^\]]*?#(\d+)[^\]]*)\]\s*(?:\(([^)]+)\))?\s*(.*)$/u', $subject, $m) !== 1) {
            return null;
        }

        $issueId = $m[2];
        $statusHint = isset($m[3]) ? trim($m[3]) : '';
        $titleTail = trim($m[4] ?? '');
        $bracket = trim($m[1]);

        $title = $titleTail !== ''
            ? $titleTail
            : (preg_match('/^(.*?)\s+-\s+.+?#\d+/u', $bracket, $b) === 1 ? trim($b[1]).' #'.$issueId : '#'.$issueId);

        return [
            'source' => ProjectTaskSource::Redmine,
            'external_key' => $issueId,
            'title' => mb_strlen($title) > 180 ? mb_substr($title, 0, 177).'…' : $title,
            'status' => $this->statusFromHint($statusHint),
        ];
    }

    /**
     * @return array{
     *     source: ProjectTaskSource,
     *     external_key: string,
     *     title: string|null,
     *     status: ProjectTaskStatus|null
     * }|null
     */
    private function extractJira(string $subject): ?array
    {
        // [Jira] (GIG-32) «Title»  or bare GIG-32
        if (preg_match('/\(([A-Z][A-Z0-9]+-\d+)\)/u', $subject, $m) === 1
            || preg_match('/\b([A-Z][A-Z0-9]+-\d+)\b/u', $subject, $m) === 1
        ) {
            $key = $m[1];
            $title = $subject;
            if (preg_match('/[«"]([^»"]+)[»"]/u', $subject, $t) === 1) {
                $title = $t[1];
            }

            return [
                'source' => ProjectTaskSource::Jira,
                'external_key' => $key,
                'title' => mb_strlen($title) > 180 ? mb_substr($title, 0, 177).'…' : $title,
                'status' => null,
            ];
        }

        return null;
    }

    private function statusFromHint(string $hint): ?ProjectTaskStatus
    {
        $hint = mb_strtolower(trim($hint));
        if ($hint === '') {
            return null;
        }

        return match (true) {
            str_contains($hint, 'обратная связь') => ProjectTaskStatus::Feedback,
            str_contains($hint, 'в работе') => ProjectTaskStatus::InProgress,
            str_contains($hint, 'новая') || str_contains($hint, 'новый') => ProjectTaskStatus::Open,
            str_contains($hint, 'решен') || str_contains($hint, 'закрыт') || str_contains($hint, 'готов') => ProjectTaskStatus::Done,
            default => ProjectTaskStatus::Unknown,
        };
    }
}
