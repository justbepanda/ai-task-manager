<?php

namespace App\Hermes\DTO;

use App\Models\Event;
use App\Models\Message;

readonly class HermesProcessResult
{
    public function __construct(
        public Message $message,
        public bool $usedLlm,
        public bool $needsLlm,
        public ?Event $event = null,
    ) {}
}
