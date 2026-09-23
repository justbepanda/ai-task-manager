<?php

declare(strict_types=1);

namespace App\Domain\Conversations;

use App\AI\DTO\AnalysisResult;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Laravel-side agent memory: compact facts per message + rolling conversation state.
 * Hermes only proposes; this service writes after validation.
 */
final class UpdateConversationAgentMemory
{
    private const LIST_LIMIT = 8;

    /**
     * @param  array<string, mixed>|null  $rawAgentFacts  optional LLM block (soft)
     * @return array<string, mixed> persisted message.agent_facts
     */
    public function apply(Message $message, AnalysisResult $result, ?array $rawAgentFacts = null): array
    {
        $facts = $this->buildMessageFacts($message, $result, $rawAgentFacts);

        $message->forceFill(['agent_facts' => $facts])->save();

        if ($message->conversation_id === null) {
            return $facts;
        }

        $conversation = Conversation::query()
            ->lockForUpdate()
            ->find($message->conversation_id);

        if ($conversation === null) {
            return $facts;
        }

        $conversation->forceFill([
            'agent_state' => $this->mergeConversationState(
                is_array($conversation->agent_state) ? $conversation->agent_state : [],
                $facts,
                $message,
                $result,
            ),
        ])->save();

        return $facts;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *     summary: string,
     *     event_type: string,
     *     topic: string|null,
     *     open_questions: list<string>,
     *     commitments: list<string>,
     *     waiting_for: string|null,
     *     key_points: list<string>,
     *     requires_manager: bool,
     *     suggested_action: string,
     *     reason: string|null
     * }
     */
    private function buildMessageFacts(Message $message, AnalysisResult $result, ?array $raw): array
    {
        $fromLlm = $this->normalizeOptionalFacts($raw);

        $openQuestions = $fromLlm['open_questions'];
        $commitments = $fromLlm['commitments'];
        $keyPoints = $fromLlm['key_points'];
        $topic = $fromLlm['topic'];
        $waitingFor = $fromLlm['waiting_for'];

        if ($openQuestions === [] && $result->requiresManager) {
            $seed = trim((string) ($result->reason ?: $result->summary));
            if ($seed !== '') {
                $openQuestions[] = $seed;
            }
        }

        if ($keyPoints === []) {
            $keyPoints[] = $result->summary;
        }

        if ($topic === null || $topic === '') {
            $topic = $this->guessTopic($message, $result);
        }

        return [
            'summary' => $result->summary,
            'event_type' => $result->eventType->value,
            'topic' => $topic,
            'open_questions' => $this->uniqueLimited($openQuestions),
            'commitments' => $this->uniqueLimited($commitments),
            'waiting_for' => $waitingFor,
            'key_points' => $this->uniqueLimited($keyPoints),
            'requires_manager' => $result->requiresManager,
            'suggested_action' => $result->suggestedAction->value,
            'reason' => $result->reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function mergeConversationState(
        array $state,
        array $facts,
        Message $message,
        AnalysisResult $result,
    ): array {
        $open = $this->uniqueLimited(array_merge(
            $this->stringList($state['open_questions'] ?? null),
            $this->stringList($facts['open_questions'] ?? null),
        ));
        $commitments = $this->uniqueLimited(array_merge(
            $this->stringList($state['commitments'] ?? null),
            $this->stringList($facts['commitments'] ?? null),
        ));

        $waitingFor = is_string($facts['waiting_for'] ?? null) && trim((string) $facts['waiting_for']) !== ''
            ? trim((string) $facts['waiting_for'])
            : (is_string($state['waiting_for'] ?? null) ? (string) $state['waiting_for'] : null);

        $status = $this->resolveStatus($result, $waitingFor);

        return [
            'topic' => is_string($facts['topic'] ?? null) && trim((string) $facts['topic']) !== ''
                ? trim((string) $facts['topic'])
                : (is_string($state['topic'] ?? null) ? (string) $state['topic'] : null),
            'status' => $status,
            'open_questions' => $open,
            'commitments' => $commitments,
            'waiting_for' => $waitingFor,
            'last_summary' => $result->summary,
            'last_event_type' => $result->eventType->value,
            'last_requires_manager' => $result->requiresManager,
            'updated_from_message_id' => $message->id,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *     topic: string|null,
     *     open_questions: list<string>,
     *     commitments: list<string>,
     *     waiting_for: string|null,
     *     key_points: list<string>
     * }
     */
    private function normalizeOptionalFacts(?array $raw): array
    {
        if ($raw === null) {
            return [
                'topic' => null,
                'open_questions' => [],
                'commitments' => [],
                'waiting_for' => null,
                'key_points' => [],
            ];
        }

        $topic = isset($raw['topic']) && is_string($raw['topic']) ? trim($raw['topic']) : null;
        $waiting = isset($raw['waiting_for']) && is_string($raw['waiting_for']) ? trim($raw['waiting_for']) : null;

        return [
            'topic' => $topic !== '' ? $topic : null,
            'open_questions' => $this->stringList($raw['open_questions'] ?? null),
            'commitments' => $this->stringList($raw['commitments'] ?? null),
            'waiting_for' => $waiting !== '' ? $waiting : null,
            'key_points' => $this->stringList($raw['key_points'] ?? null),
        ];
    }

    private function guessTopic(Message $message, AnalysisResult $result): ?string
    {
        $subject = trim((string) ($message->subject ?? ''));
        if ($subject !== '') {
            return mb_strlen($subject) > 120 ? mb_substr($subject, 0, 117).'…' : $subject;
        }

        $summary = trim($result->summary);

        return $summary !== '' ? (mb_strlen($summary) > 120 ? mb_substr($summary, 0, 117).'…' : $summary) : null;
    }

    private function resolveStatus(AnalysisResult $result, ?string $waitingFor): string
    {
        if ($result->requiresManager || in_array($result->suggestedAction->value, ['reply', 'review', 'follow_up'], true)) {
            return 'waiting_us';
        }

        if (is_string($waitingFor) && $waitingFor !== '') {
            return 'waiting_client';
        }

        return 'active';
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
