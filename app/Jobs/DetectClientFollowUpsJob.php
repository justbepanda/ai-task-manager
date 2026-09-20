<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Activity\ActivityLogRecorder;
use App\Domain\ManagerActions\DetectClientFollowUps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DetectClientFollowUpsJob implements ShouldQueue
{
    use Queueable;

    public function handle(
        DetectClientFollowUps $detector,
        ActivityLogRecorder $activity,
    ): void {
        try {
            $result = $detector->handle();

            $activity->record(
                category: 'system',
                action: 'follow_up_scan',
                status: 'ok',
                summary: sprintf(
                    'Скан тишины: создано follow_up — %d (просмотрено %d)',
                    $result['created'],
                    $result['scanned'],
                ),
                details: $result,
            );
        } catch (Throwable $e) {
            $activity->record(
                category: 'system',
                action: 'follow_up_scan',
                status: 'failed',
                summary: 'Ошибка скана follow_up: '.$e->getMessage(),
                details: ['exception' => $e::class],
            );

            throw $e;
        }
    }
}
