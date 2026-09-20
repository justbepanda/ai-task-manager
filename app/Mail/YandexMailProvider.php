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
     * @return list<string>
     */
    public function listSyncFolders(): array
    {
        $config = $this->validatedConfig();

        if (! $config['sync_all_folders']) {
            return [$config['folder']];
        }

        $mailbox = new ImapMailbox($config);

        try {
            $mailbox->open($config['folder']);
            $folders = $mailbox->listFolderNames();
        } catch (Throwable $e) {
            throw new MailProviderException(
                'IMAP list folders failed: '.$this->sanitizeMessage($e->getMessage(), $config),
                0,
                $e instanceof \Exception ? $e : null,
            );
        } finally {
            $mailbox->close();
        }

        $filtered = $this->filterExcludedFolders($folders, $config['folder_exclude']);

        if ($filtered === []) {
            return [$config['folder']];
        }

        // Keep INBOX first for predictable first-run behaviour.
        usort($filtered, static function (string $a, string $b): int {
            if (strcasecmp($a, 'INBOX') === 0) {
                return -1;
            }
            if (strcasecmp($b, 'INBOX') === 0) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        return $filtered;
    }

    /**
     * @return iterable<int, NormalizedIncomingMessage>
     */
    public function fetchNewMessages(?MailSyncState $state = null): iterable
    {
        $config = $this->validatedConfig();
        $folder = trim((string) ($state?->folder ?: $config['folder']));
        if ($folder === '') {
            $folder = 'INBOX';
        }

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

            $uids = $this->resolveUids($mailbox, $lastUid, $firstSyncLimit, $folder);

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
    private function resolveUids(ImapMailbox $mailbox, int $lastUid, int $firstSyncLimit, string $folder): array
    {
        if ($lastUid > 0) {
            return $mailbox->searchUids('UID '.($lastUid + 1).':*');
        }

        // First-run policy: import only the newest N messages per folder.
        $allUids = $mailbox->searchUids('ALL');

        if ($allUids === []) {
            return [];
        }

        rsort($allUids);

        $limited = array_slice($allUids, 0, max(1, $firstSyncLimit));
        sort($limited);

        Log::info('First IMAP sync limited to newest messages', [
            'provider' => MailSource::Yandex->value,
            'folder' => $folder,
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
     * @param  list<string>  $folders
     * @param  list<string>  $excludeNeedles
     * @return list<string>
     */
    private function filterExcludedFolders(array $folders, array $excludeNeedles): array
    {
        if ($excludeNeedles === []) {
            return $folders;
        }

        $kept = [];
        foreach ($folders as $folder) {
            $haystack = mb_strtolower($folder);
            $decoded = function_exists('imap_utf7_decode')
                ? mb_strtolower((string) @imap_utf7_decode($folder))
                : $haystack;

            $excluded = false;
            foreach ($excludeNeedles as $needle) {
                $needle = mb_strtolower(trim($needle));
                if ($needle === '') {
                    continue;
                }
                if (str_contains($haystack, $needle) || str_contains($decoded, $needle)) {
                    $excluded = true;
                    break;
                }
            }

            if (! $excluded) {
                $kept[] = $folder;
            }
        }

        return array_values($kept);
    }

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     folder: string,
     *     first_sync_limit: int,
     *     sync_all_folders: bool,
     *     folder_exclude: list<string>
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

        $exclude = $config['folder_exclude'] ?? [];
        if (! is_array($exclude)) {
            $exclude = [];
        }

        return [
            'host' => (string) ($config['host'] ?? 'imap.yandex.ru'),
            'port' => (int) ($config['port'] ?? 993),
            'encryption' => (string) ($config['encryption'] ?? 'ssl'),
            'username' => $username,
            'password' => $password,
            'folder' => (string) ($config['folder'] ?? 'INBOX'),
            'first_sync_limit' => (int) ($config['first_sync_limit'] ?? 100),
            'sync_all_folders' => (bool) ($config['sync_all_folders'] ?? true),
            'folder_exclude' => array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $exclude,
            ), static fn (string $item): bool => $item !== '')),
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
