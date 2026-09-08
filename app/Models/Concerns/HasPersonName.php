<?php

namespace App\Models\Concerns;

trait HasPersonName
{
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '')));
    }
}
