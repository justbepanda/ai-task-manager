<?php

use App\Jobs\DetectClientFollowUpsJob;
use App\Jobs\SyncYandexMailJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $minutes = max(1, (int) config('mail_providers.sync_interval_minutes', 5));
        $mailSync = $schedule->job(new SyncYandexMailJob);

        if ($minutes === 1) {
            $mailSync->everyMinute();
        } else {
            $mailSync->cron("*/{$minutes} * * * *");
        }

        if ((bool) config('ai.follow_up.enabled', true)) {
            $hour = max(0, min(23, (int) config('ai.follow_up.schedule_hour', 9)));
            $schedule->job(new DetectClientFollowUpsJob)->dailyAt(sprintf('%02d:00', $hour));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
