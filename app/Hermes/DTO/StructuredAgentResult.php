<?php

namespace App\Hermes\DTO;

use App\AI\DTO\AnalysisResult;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;

/**
 * Validated Hermes output after Laravel checks (Structured Agent Result).
 * Alias conceptually over AnalysisResult for the Hermes pipeline.
 */
readonly class StructuredAgentResult
{
    public function __construct(
        public MessageClassification $classification,
        public ?int $projectId,
        public float $confidence,
        public EventType $eventType,
        public string $summary,
        public bool $requiresManager,
        public ManagerActionType $suggestedAction,
        public ?string $draftReply,
        public ?string $reason,
    ) {}

    public static function fromAnalysisResult(AnalysisResult $result): self
    {
        return new self(
            classification: $result->classification,
            projectId: $result->projectId,
            confidence: $result->confidence,
            eventType: $result->eventType,
            summary: $result->summary,
            requiresManager: $result->requiresManager,
            suggestedAction: $result->suggestedAction,
            draftReply: $result->draftReply,
            reason: $result->reason,
        );
    }

    public function toAnalysisResult(): AnalysisResult
    {
        return new AnalysisResult(
            classification: $this->classification,
            projectId: $this->projectId,
            confidence: $this->confidence,
            eventType: $this->eventType,
            summary: $this->summary,
            requiresManager: $this->requiresManager,
            suggestedAction: $this->suggestedAction,
            draftReply: $this->draftReply,
            reason: $this->reason,
        );
    }
}
