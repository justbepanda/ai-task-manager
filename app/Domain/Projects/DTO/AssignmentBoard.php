<?php

namespace App\Domain\Projects\DTO;

use App\Models\Message;

readonly class AssignmentBoard
{
    /**
     * @param  list<AssignmentGroup>  $groups
     * @param  list<Message>  $unmatched
     */
    public function __construct(
        public array $groups,
        public array $unmatched,
    ) {}
}
