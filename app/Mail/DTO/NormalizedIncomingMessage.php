<?php

namespace App\Mail\DTO;

use App\Enums\MailSource;
use Carbon\CarbonInterface;

final class NormalizedIncomingMessage
{
    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $cc
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly MailSource $source,
        public readonly string $externalId,
        public readonly string $sender,
        public readonly array $recipients,
        public readonly array $cc,
        public readonly ?string $subject,
        public readonly ?string $body,
        public readonly CarbonInterface $receivedAt,
        public readonly array $headers = [],
        public readonly array $metadata = [],
        public readonly ?string $rawHeaders = null,
        public readonly ?string $rawBody = null,
    ) {}

    /**
     * True when IMAP returned no usable message content (failed open / empty fetch).
     */
    public function isEmpty(): bool
    {
        $subject = trim((string) $this->subject);
        $sender = trim($this->sender);
        $body = trim((string) $this->body);
        $rawHeaders = trim((string) ($this->rawHeaders ?? ''));

        return $subject === '' && $sender === '' && $body === '' && $rawHeaders === '';
    }
}
