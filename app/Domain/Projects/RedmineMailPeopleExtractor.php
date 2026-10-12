<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Extracts people fields from Redmine notification bodies / subjects.
 */
class RedmineMailPeopleExtractor
{
    /**
     * @return array{
     *     author: ?string,
     *     assignee: ?string,
     *     requires_feedback: bool
     * }
     */
    public function extract(string $subject, string $body): array
    {
        return [
            'author' => $this->extractLabeledValue($body, ['Автор', 'Author']),
            'assignee' => $this->extractLabeledValue($body, ['Назначена', 'Назначен', 'Assignee']),
            'requires_feedback' => $this->subjectRequiresFeedback($subject),
        ];
    }

    public function subjectRequiresFeedback(string $subject): bool
    {
        return preg_match('/\(\s*обратная\s+связь\s*\)/ui', $subject) === 1;
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractLabeledValue(string $body, array $labels): ?string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // Strip simple HTML tags from multipart leftovers.
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            // Allow list markers: "* Автор:", "- Автор:", "• Автор:"
            if (preg_match('/(?:^|\n)\s*(?:[\*\-•]\s*)?'.$quoted.'\s*:\s*(.+?)\s*(?=\n|$)/ui', $plain, $matches) === 1) {
                $value = trim($matches[1]);
                $value = trim($value, " \t\"'«»");
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
