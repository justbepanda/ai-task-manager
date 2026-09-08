<?php

namespace App\AI\DTO;

use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;

readonly class AnalysisResult
{
    public function __construct(
        public MessageClassification $classification,
        public ?int $projectId,
        public float $confidence,
        public EventType $eventType,
        public string $summary,
        public bool $requiresManager,
        public ManagerActionType $suggestedAction,
        public ?string $draftReply = null,
        public ?string $reason = null,
    ) {}
}
