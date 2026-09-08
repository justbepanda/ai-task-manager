<?php

namespace App\Domain\Classification\DTO;

use App\Enums\MessageClassification;

readonly class ClassificationResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public MessageClassification $classification,
        public float $confidence,
        public array $reasons = [],
    ) {}
}
