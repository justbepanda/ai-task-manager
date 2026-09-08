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
     * @return array{imported: int, skipped: int, last_uid: int}
     */
    public function sync(MailSource $source = MailSource::Yandex): array
    {
        $folder = (string) config("mail_providers.{$source->value}.folder", 'INBOX');

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

        Log::info('Mail sync completed', [
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
