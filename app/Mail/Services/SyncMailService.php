<?php

namespace App\Mail\Services;

use App\Enums\MailSource;
use App\Jobs\ClassifyMessageJob;
use App\Mail\Contracts\MailProvider;
use App\Models\MailSyncState;
use Illuminate\Support\Facades\Log;

class SyncMailService
{
    public function __construct(
        private readonly MailProvider $provider,
        private readonly PersistIncomingMessage $persist,
    ) {}

    /**
     * @return array{imported: int, skipped: int, last_uid: int, folders: int}
     */
    public function sync(MailSource $source = MailSource::Yandex): array
    {
        $folders = $this->provider->listSyncFolders();
        if ($folders === []) {
            $folders = [(string) config("mail_providers.{$source->value}.folder", 'INBOX')];
        }

        $imported = 0;
        $skipped = 0;
        $maxUidOverall = 0;

        foreach ($folders as $folder) {
            $folder = trim((string) $folder);
            if ($folder === '') {
                continue;
            }

            $result = $this->syncFolder($source, $folder);
            $imported += $result['imported'];
            $skipped += $result['skipped'];
            $maxUidOverall = max($maxUidOverall, $result['last_uid']);
        }

        Log::info('Mail sync completed', [
            'provider' => $source->value,
            'folders' => count($folders),
            'imported' => $imported,
            'skipped' => $skipped,
            'last_uid' => $maxUidOverall,
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'last_uid' => $maxUidOverall,
            'folders' => count($folders),
        ];
    }

    /**
     * @return array{imported: int, skipped: int, last_uid: int}
     */
    private function syncFolder(MailSource $source, string $folder): array
    {
        $state = MailSyncState::query()->firstOrCreate(
            [
                'provider' => $source->value,
                'folder' => $folder,
            ],
            [
                'last_uid' => 0,
                'uidvalidity' => null,
            ],
        );

        $imported = 0;
        $skipped = 0;
        $maxUid = (int) $state->last_uid;
        $uidvalidity = $state->uidvalidity;

        foreach ($this->provider->fetchNewMessages($state) as $normalized) {
            if ($normalized->isEmpty()) {
                $skipped++;
                Log::warning('Skipping empty IMAP message (likely unreadable folder name)', [
                    'provider' => $source->value,
                    'folder' => $folder,
                    'external_id' => $normalized->externalId,
                    'imap_uid' => $normalized->metadata['imap_uid'] ?? null,
                    'uidvalidity' => $normalized->metadata['uidvalidity'] ?? null,
                ]);

                $uid = (int) ($normalized->metadata['imap_uid'] ?? 0);
                if ($uid > $maxUid) {
                    $maxUid = $uid;
                }
                if (isset($normalized->metadata['uidvalidity'])) {
                    $uidvalidity = (int) $normalized->metadata['uidvalidity'];
                }

                continue;
            }

            $result = $this->persist->handle($normalized);

            if ($result['created']) {
                $imported++;
                ClassifyMessageJob::dispatch($result['message']->id);
            } else {
                $skipped++;
            }

            $uid = (int) ($normalized->metadata['imap_uid'] ?? 0);
            if ($uid > $maxUid) {
                $maxUid = $uid;
            }

            if (isset($normalized->metadata['uidvalidity'])) {
                $uidvalidity = (int) $normalized->metadata['uidvalidity'];
            }
        }

        $state->fill([
            'last_uid' => $maxUid,
            'uidvalidity' => $uidvalidity,
            'last_synced_at' => now(),
        ]);
        $state->save();

        Log::info('Mail folder sync completed', [
            'provider' => $source->value,
            'folder' => $folder,
            'imported' => $imported,
            'skipped' => $skipped,
            'last_uid' => $maxUid,
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'last_uid' => $maxUid,
        ];
    }
}
