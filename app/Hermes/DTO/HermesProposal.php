<?php

namespace App\Hermes\DTO;

use App\AI\DTO\MessageContext;

/**
 * Raw Hermes proposal before Laravel validation.
 */
readonly class HermesProposal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public MessageContext $context,
        public array $payload,
    ) {}
}
