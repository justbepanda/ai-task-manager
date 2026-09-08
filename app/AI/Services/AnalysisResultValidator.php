<?php

namespace App\AI\Services;

use App\AI\DTO\AnalysisResult;
use App\AI\DTO\MessageContext;
use App\AI\Exceptions\AnalysisValidationException;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;

class AnalysisResultValidator
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data, MessageContext $context): AnalysisResult
    {
        $classification = $this->enumFrom($data['classification'] ?? null, MessageClassification::class, 'classification');
        $eventType = $this->enumFrom($data['event_type'] ?? null, EventType::class, 'event_type');
        $suggestedAction = $this->enumFrom($data['suggested_action'] ?? null, ManagerActionType::class, 'suggested_action');

        if (! array_key_exists('confidence', $data) || ! is_numeric($data['confidence'])) {
            throw new AnalysisValidationException('confidence must be a float between 0 and 1');
        }

        $confidence = (float) $data['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new AnalysisValidationException('confidence must be a float between 0 and 1');
        }

        if (! array_key_exists('requires_manager', $data) || ! is_bool($data['requires_manager'])) {
            throw new AnalysisValidationException('requires_manager must be a boolean');
        }

        $summary = $data['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') {
            throw new AnalysisValidationException('summary is required and must be a non-empty string');
        }

        $draftReply = $data['draft_reply'] ?? null;
        if ($draftReply !== null && ! is_string($draftReply)) {
            throw new AnalysisValidationException('draft_reply must be a string or null');
        }

        if ($suggestedAction === ManagerActionType::Reply) {
            if (! is_string($draftReply) || trim($draftReply) === '') {
                throw new AnalysisValidationException('draft_reply is required when suggested_action is reply');
            }
        }

        $reason = $data['reason'] ?? null;
        if ($reason !== null && ! is_string($reason)) {
            throw new AnalysisValidationException('reason must be a string or null');
        }

        $projectId = $this->resolveProjectId($data['project_id'] ?? null, $context);

        return new AnalysisResult(
            classification: $classification,
            projectId: $projectId,
            confidence: $confidence,
            eventType: $eventType,
            summary: trim($summary),
            requiresManager: $data['requires_manager'],
            suggestedAction: $suggestedAction,
            draftReply: is_string($draftReply) ? $draftReply : null,
            reason: is_string($reason) ? $reason : null,
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return T
     */
    private function enumFrom(mixed $value, string $enumClass, string $field): \BackedEnum
    {
        if (! is_string($value) || $value === '') {
            throw new AnalysisValidationException("{$field} is required");
        }

        $enum = $enumClass::tryFrom($value);
        if ($enum === null) {
            throw new AnalysisValidationException("Unknown {$field}: {$value}");
        }

        return $enum;
    }

    private function resolveProjectId(mixed $rawProjectId, MessageContext $context): ?int
    {
        $messageProjectId = isset($context->message['project_id']) && is_numeric($context->message['project_id'])
            ? (int) $context->message['project_id']
            : null;

        $aiProjectId = null;
        if ($rawProjectId !== null && $rawProjectId !== '') {
            if (! is_numeric($rawProjectId)) {
                throw new AnalysisValidationException('project_id must be an integer or null');
            }
            $aiProjectId = (int) $rawProjectId;
        }

        // Message already has a project — keep it; do not invent / override from AI.
        if ($messageProjectId !== null) {
            return $messageProjectId;
        }

        // No project on message: accept AI id only if it is in candidate list.
        if ($aiProjectId === null) {
            return null;
        }

        if (! in_array($aiProjectId, $context->candidateProjectIds, true)) {
            // Soft ignore — do not invent projects outside candidates.
            return null;
        }

        return $aiProjectId;
    }
}
