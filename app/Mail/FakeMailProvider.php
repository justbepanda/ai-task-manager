<?php

namespace App\Mail;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\NormalizedIncomingMessage;
use App\Mail\Exceptions\MailProviderException;
use App\Models\MailSyncState;
use Throwable;

class FakeMailProvider implements MailProvider
{
    /** @var list<NormalizedIncomingMessage> */
    private array $messages;

    private ?Throwable $exception;

    /**
     * @param  list<NormalizedIncomingMessage>  $messages
     */
    public function __construct(array $messages = [], ?Throwable $exception = null)
    {
        $this->messages = $messages;
        $this->exception = $exception;
    }

    /**
     * @param  list<NormalizedIncomingMessage>  $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function throwOnFetch(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function fetchNewMessages(?MailSyncState $state = null): iterable
    {
        if ($this->exception !== null) {
            throw $this->exception instanceof MailProviderException
                ? $this->exception
                : new MailProviderException($this->exception->getMessage(), 0, $this->exception);
        }

        $lastUid = (int) ($state?->last_uid ?? 0);

        foreach ($this->messages as $message) {
            $uid = (int) ($message->metadata['imap_uid'] ?? 0);

            if ($lastUid > 0 && $uid > 0 && $uid <= $lastUid) {
                continue;
            }

            yield $message;
        }
    }
}
