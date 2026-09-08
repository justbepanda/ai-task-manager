<?php

namespace App\Domain\Projects\DTO;

use App\Models\Message;

readonly class AssignmentSuggestion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public Message $message,
        public float $score,
        public array $reasons,
        public bool $ambiguous,
    ) {}
}
