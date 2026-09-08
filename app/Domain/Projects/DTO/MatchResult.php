<?php

namespace App\Domain\Projects\DTO;

readonly class MatchResult
{
    /**
     * @param  list<ProjectCandidate>  $candidates
     * @param  list<string>  $reasons
     */
    public function __construct(
        public ?int $projectId,
        public bool $ambiguous,
        public array $candidates = [],
        public array $reasons = [],
    ) {}
}
