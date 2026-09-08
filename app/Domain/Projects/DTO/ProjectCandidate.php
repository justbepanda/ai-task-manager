<?php

namespace App\Domain\Projects\DTO;

readonly class ProjectCandidate
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $projectId,
        public float $score,
        public array $reasons = [],
    ) {}
}
