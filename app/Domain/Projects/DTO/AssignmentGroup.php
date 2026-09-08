<?php

namespace App\Domain\Projects\DTO;

use App\Models\Project;

readonly class AssignmentGroup
{
    /**
     * @param  list<AssignmentSuggestion>  $suggestions
     * @param  list<string>  $suggestedAliases  aliases to seed as keywords when creating
     */
    public function __construct(
        public ?Project $project,
        public string $title,
        public bool $isNew,
        public int $existingCount,
        public array $suggestions,
        public ?string $suggestedName = null,
        public array $suggestedAliases = [],
    ) {}
}
