<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\AI\DTO\AnalysisResult;
use App\Models\Message;
use App\Models\Project;

/**
 * Laravel-side project memory after Hermes validation.
 * Hermes only proposes; this service writes projects.agent_state.
 */
final class UpdateProjectAgentState
{
    private const LIST_LIMIT = 8;

    /**
     * @return array<string, mixed> persisted project.agent_state
     */
    public function apply(Project $project, Message $message, AnalysisResult $result): array
    {
        $facts = is_array($message->agent_facts) ? $message->agent_facts : [];
        $previous = is_array($project->agent_state) ? $project->agent_state : [];

        $topic = $this->stringOrNull($facts['topic'] ?? null)
            ?? $this->stringOrNull($previous['topic'] ?? null)
            ?? $this->guessTopic($message, $result);

        $waitingFor = $this->stringOrNull($facts['waiting_for'] ?? null)
            ?? $this->stringOrNull($previous['waiting_for'] ?? null);

        $openQuestions = $this->uniqueLimited(array_merge(
            $this->stringList($previous['open_questions'] ?? null),
            $this->stringList($facts['open_questions'] ?? null),
        ));

        if ($openQuestions === [] && $result->requiresManager) {
            $seed = trim((string) ($result->reason ?: $result->summary));
            if ($seed !== '') {
                $openQuestions[] = $seed;
            }
        }

        $state = [
            'topic' => $topic,
            'waiting_for' => $waitingFor,
            'open_questions' => $openQuestions,
            'last_summary' => $result->summary,
            'last_event_type' => $result->eventType->value,
            'last_requires_manager' => $result->requiresManager,
            'updated_from_message_id' => $message->id,
            'updated_at' => now()->toIso8601String(),
        ];

        $project->forceFill(['agent_state' => $state])->save();

        return $state;
    }

    private function guessTopic(Message $message, AnalysisResult $result): ?string
    {
        $subject = trim((string) ($message->subject ?? ''));
        if ($subject !== '') {
            return mb_strlen($subject) > 120 ? mb_substr($subject, 0, 117).'…' : $subject;
        }

        $summary = trim($result->summary);

        return $summary !== ''
            ? (mb_strlen($summary) > 120 ? mb_substr($summary, 0, 117).'…' : $summary)
            : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function uniqueLimited(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= self::LIST_LIMIT) {
                break;
            }
        }

        return $out;
    }
}
