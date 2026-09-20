<?php

namespace App\Mail\Imap;

use App\Mail\Exceptions\MailProviderException;

/**
 * Thin wrapper around PHP imap_* for testability.
 *
 * @internal
 */
class ImapMailbox
{
    /** @var resource|\IMAP\Connection|null */
    private mixed $connection = null;

    /**
     * @param  array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     folder: string
     * }  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public function open(?string $folder = null): void
    {
        $folder ??= $this->config['folder'] ?? 'INBOX';
        $mailbox = $this->mailboxString($folder);

        $connection = @imap_open(
            $mailbox,
            (string) $this->config['username'],
            (string) $this->config['password'],
            OP_READONLY,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI'],
        );

        if ($connection === false) {
            throw new MailProviderException(
                'Failed to open IMAP mailbox: '.$this->lastError()
            );
        }

        $this->connection = $connection;
    }

    public function reopen(string $folder): void
    {
        $ok = @imap_reopen($this->connection(), $this->mailboxString($folder), OP_READONLY);

        if ($ok === false) {
            throw new MailProviderException(
                'Failed to reopen IMAP folder '.$folder.': '.$this->lastError()
            );
        }
    }

    /**
     * @return list<string> Folder names relative to mailbox root (e.g. INBOX, Projects/Foo).
     */
    public function listFolderNames(): array
    {
        $root = $this->mailboxRoot();
        $listed = @imap_list($this->connection(), $root, '*');

        if ($listed === false) {
            return [(string) ($this->config['folder'] ?? 'INBOX')];
        }

        $folders = [];
        foreach ($listed as $fullName) {
            $fullName = (string) $fullName;
            if (! str_starts_with($fullName, $root)) {
                continue;
            }

            $name = substr($fullName, strlen($root));
            if ($name === false || $name === '') {
                continue;
            }

            $folders[] = $name;
        }

        $folders = array_values(array_unique($folders));
        sort($folders);

        return $folders === [] ? [(string) ($this->config['folder'] ?? 'INBOX')] : $folders;
    }

    public function getUidValidity(): int
    {
        $check = imap_check($this->connection());

        if ($check === false) {
            throw new MailProviderException('Failed to read IMAP mailbox status: '.$this->lastError());
        }

        return (int) ($check->uidvalidity ?? 0);
    }

    /**
     * @return list<int>
     */
    public function searchUids(string $criteria): array
    {
        $result = imap_search($this->connection(), $criteria, SE_UID);

        if ($result === false) {
            return [];
        }

        $uids = array_map('intval', $result);
        sort($uids);

        return $uids;
    }

    public function fetchHeader(int $uid): string
    {
        $header = imap_fetchheader($this->connection(), $uid, FT_UID);

        if ($header === false) {
            throw new MailProviderException("Failed to fetch IMAP headers for UID {$uid}: ".$this->lastError());
        }

        return $header;
    }

    public function fetchStructure(int $uid): object
    {
        $structure = imap_fetchstructure($this->connection(), $uid, FT_UID);

        if ($structure === false) {
            throw new MailProviderException("Failed to fetch IMAP structure for UID {$uid}: ".$this->lastError());
        }

        return $structure;
    }

    public function fetchBody(int $uid, string $section = ''): string
    {
        if ($section === '') {
            $body = imap_body($this->connection(), $uid, FT_UID);
        } else {
            $body = imap_fetchbody($this->connection(), $uid, $section, FT_UID);
        }

        if ($body === false) {
            throw new MailProviderException("Failed to fetch IMAP body for UID {$uid}: ".$this->lastError());
        }

        return $body;
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    private function mailboxString(string $folder): string
    {
        return $this->mailboxRoot().$folder;
    }

    private function mailboxRoot(): string
    {
        $host = $this->config['host'];
        $port = (int) $this->config['port'];
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'ssl'));
        $flags = $encryption === 'ssl' ? '/imap/ssl' : '/imap/tls';

        return sprintf('{%s:%d%s}', $host, $port, $flags);
    }

    /** @return resource|\IMAP\Connection */
    private function connection(): mixed
    {
        if ($this->connection === null) {
            throw new MailProviderException('IMAP mailbox is not open.');
        }

        return $this->connection;
    }

    private function lastError(): string
    {
        $errors = imap_errors() ?: [];
        $last = imap_last_error() ?: (end($errors) ?: 'unknown IMAP error');

        return $this->sanitizeError((string) $last);
    }

    private function sanitizeError(string $error): string
    {
        $password = (string) ($this->config['password'] ?? '');
        $username = (string) ($this->config['username'] ?? '');

        if ($password !== '') {
            $error = str_replace($password, '[redacted]', $error);
        }

        if ($username !== '') {
            $error = str_replace($username, '[redacted]', $error);
        }

        return $error;
    }
}
