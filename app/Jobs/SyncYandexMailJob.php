<?php

namespace App\Jobs;

use App\Domain\Activity\ActivityLogRecorder;
use App\Enums\MailSource;
use App\Mail\Services\SyncMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncYandexMailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $userId = null,
    ) {}

    public function handle(
        SyncMailService $syncMailService,
        ActivityLogRecorder $activity,
    ): void {
        try {
            $result = $syncMailService->sync(MailSource::Yandex);

            $activity->record(
                category: 'mail',
                action: 'sync',
                status: 'ok',
                summary: sprintf(
                    'Синхронизация почты: импортировано %d, пропущено %d',
                    $result['imported'],
                    $result['skipped'],
                ),
                details: $result,
                userId: $this->userId,
            );
        } catch (Throwable $e) {
            $activity->record(
                category: 'mail',
                action: 'sync',
                status: 'failed',
                summary: 'Ошибка синхронизации почты: '.$e->getMessage(),
                details: ['exception' => $e::class],
                userId: $this->userId,
            );

            throw $e;
        }
    }
}
