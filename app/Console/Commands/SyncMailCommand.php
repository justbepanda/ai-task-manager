<?php

namespace App\Console\Commands;

use App\Enums\MailSource;
use App\Jobs\SyncYandexMailJob;
use App\Mail\Services\SyncMailService;
use Illuminate\Console\Command;

class SyncMailCommand extends Command
{
    protected $signature = 'mail:sync {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Sync new messages from the configured mail provider (Yandex IMAP)';

    public function handle(SyncMailService $syncMailService): int
    {
        if ($this->option('sync')) {
            $result = $syncMailService->sync(MailSource::Yandex);

            $this->info(sprintf(
                'Mail sync finished: imported=%d skipped=%d last_uid=%d',
                $result['imported'],
                $result['skipped'],
                $result['last_uid'],
            ));

            return self::SUCCESS;
        }

        SyncYandexMailJob::dispatch();
        $this->info('SyncYandexMailJob dispatched to the queue.');

        return self::SUCCESS;
    }
}
