<?php

namespace App\Mail;

use App\Enums\MailSource;
use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\NormalizedIncomingMessage;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Imap\ImapMailbox;
use App\Mail\Imap\ImapMessageMapper;
use App\Models\MailSyncState;
use Illuminate\Support\Facades\Log;
use Throwable;

class YandexMailProvider implements MailProvider
{
    public function __construct(
        private readonly ImapMessageMapper $mapper = new ImapMessageMapper,
    ) {}

    /**
     * @return iterable<int, NormalizedIncomingMessage>
     */
    public function fetchNewMessages(?MailSyncState $state = null): iterable
    {
        $config = $this->validatedConfig();
        $folder = (string) $config['folder'];
        $firstSyncLimit = (int) ($config['first_sync_limit'] ?? 100);
        $mailbox = new ImapMailbox($config);

        try {
            $mailbox->open($folder);
            $uidvalidity = $mailbox->getUidValidity();

            $lastUid = (int) ($state?->last_uid ?? 0);

            if ($state !== null && $state->uidvalidity !== null && (int) $state->uidvalidity !== $uidvalidity) {
                Log::warning('IMAP UIDVALIDITY changed; resetting sync cursor', [
                    'provider' => MailSource::Yandex->value,
                    'folder' => $folder,
                    'previous_uidvalidity' => $state->uidvalidity,
                    'new_uidvalidity' => $uidvalidity,
                ]);
                $lastUid = 0;
            }

            $uids = $this->resolveUids($mailbox, $lastUid, $firstSyncLimit);

            foreach ($uids as $uid) {
                yield $this->fetchOne($mailbox, $folder, $uid, $uidvalidity);
            }
        } catch (MailProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MailProviderException(
                'IMAP sync failed: '.$this->sanitizeMessage($e->getMessage(), $config),
                0,
                $e,
            );
        } finally {
            $mailbox->close();
        }
    }

    /**
     * @return list<int>
     */
    private function resolveUids(ImapMailbox $mailbox, int $lastUid, int $firstSyncLimit): array
    {
        if ($lastUid > 0) {
            return $mailbox->searchUids('UID '.($lastUid + 1).':*');
        }

        // First-run policy: import only the newest N messages, never the whole mailbox.
        $allUids = $mailbox->searchUids('ALL');

        if ($allUids === []) {
            return [];
        }

        rsort($allUids);

        $limited = array_slice($allUids, 0, max(1, $firstSyncLimit));
        sort($limited);

        Log::info('First IMAP sync limited to newest messages', [
            'provider' => MailSource::Yandex->value,
            'limit' => $firstSyncLimit,
            'selected' => count($limited),
        ]);

        return $limited;
    }

    private function fetchOne(
        ImapMailbox $mailbox,
        string $folder,
        int $uid,
        int $uidvalidity,
    ): NormalizedIncomingMessage {
        $rawHeaders = $mailbox->fetchHeader($uid);
        $structure = $mailbox->fetchStructure($uid);
        $rawBody = $mailbox->fetchBody($uid);

        return $this->mapper->map(
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
            source: MailSource::Yandex,
            folder: $folder,
            uid: $uid,
            uidvalidity: $uidvalidity,
            structure: $structure,
            mailbox: $mailbox,
        );
    }

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     folder: string,
     *     first_sync_limit: int
     * }
     */
    private function validatedConfig(): array
    {
        $config = config('mail_providers.yandex', []);

        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new MailProviderException(
                'Yandex IMAP credentials are missing. Set YANDEX_IMAP_USERNAME and YANDEX_IMAP_PASSWORD.'
            );
        }

        return [
            'host' => (string) ($config['host'] ?? 'imap.yandex.ru'),
            'port' => (int) ($config['port'] ?? 993),
            'encryption' => (string) ($config['encryption'] ?? 'ssl'),
            'username' => $username,
            'password' => $password,
            'folder' => (string) ($config['folder'] ?? 'INBOX'),
            'first_sync_limit' => (int) ($config['first_sync_limit'] ?? 100),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sanitizeMessage(string $message, array $config): string
    {
        $password = (string) ($config['password'] ?? '');
        $username = (string) ($config['username'] ?? '');

        if ($password !== '') {
            $message = str_replace($password, '[redacted]', $message);
        }

        if ($username !== '') {
            $message = str_replace($username, '[redacted]', $message);
        }

        return $message;
    }
}
