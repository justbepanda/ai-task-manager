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

    /** @var list<string> */
    private array $folders;

    private ?Throwable $exception;

    /**
     * @param  list<NormalizedIncomingMessage>  $messages
     * @param  list<string>  $folders
     */
    public function __construct(array $messages = [], ?Throwable $exception = null, array $folders = ['INBOX'])
    {
        $this->messages = $messages;
        $this->exception = $exception;
        $this->folders = $folders === [] ? ['INBOX'] : array_values($folders);
    }

    /**
     * @param  list<NormalizedIncomingMessage>  $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    /**
     * @param  list<string>  $folders
     */
    public function setFolders(array $folders): void
    {
        $this->folders = $folders === [] ? ['INBOX'] : array_values($folders);
    }

    public function throwOnFetch(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    /**
     * @return list<string>
     */
    public function listSyncFolders(): array
    {
        return $this->folders;
    }

    public function fetchNewMessages(?MailSyncState $state = null): iterable
    {
        if ($this->exception !== null) {
            throw $this->exception instanceof MailProviderException
                ? $this->exception
                : new MailProviderException($this->exception->getMessage(), 0, $this->exception);
        }

        $lastUid = (int) ($state?->last_uid ?? 0);
        $folder = $state?->folder;

        foreach ($this->messages as $message) {
            $messageFolder = (string) ($message->metadata['folder'] ?? 'INBOX');
            if ($folder !== null && $folder !== '' && $messageFolder !== $folder) {
                continue;
            }

            $uid = (int) ($message->metadata['imap_uid'] ?? 0);

            if ($lastUid > 0 && $uid > 0 && $uid <= $lastUid) {
                continue;
            }

            yield $message;
        }
    }
}
