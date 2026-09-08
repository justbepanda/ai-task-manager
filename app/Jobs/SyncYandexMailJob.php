<?php

namespace App\Jobs;

use App\Enums\MailSource;
use App\Mail\Services\SyncMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncYandexMailJob implements ShouldQueue
{
    use Queueable;

    public function handle(SyncMailService $syncMailService): void
    {
        $syncMailService->sync(MailSource::Yandex);
    }
}
