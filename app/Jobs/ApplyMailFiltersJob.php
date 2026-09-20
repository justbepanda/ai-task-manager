<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Mail\ApplyMailFiltersToMessages;
use App\Domain\Activity\ActivityLogRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyMailFiltersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $userId = null,
    ) {}

    public function handle(
        ApplyMailFiltersToMessages $service,
        ActivityLogRecorder $activity,
    ): void {
        try {
            $touched = $service->apply();

            $activity->record(
                category: 'mail_filters',
                action: 'rebuild_ignored',
                status: 'ok',
                summary: "Пересборка игнорируемых: изменено писем — {$touched}",
                details: ['touched' => $touched],
                userId: $this->userId,
            );
        } catch (Throwable $e) {
            $activity->record(
                category: 'mail_filters',
                action: 'rebuild_ignored',
                status: 'failed',
                summary: 'Ошибка пересборки игнорируемых: '.$e->getMessage(),
                details: ['exception' => $e::class],
                userId: $this->userId,
            );

            throw $e;
        }
    }
}
