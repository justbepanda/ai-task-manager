<?php

namespace App\Providers;

use App\AI\Contracts\AiAnalyzer;
use App\AI\GeminiAiAnalyzer;
use App\Mail\Contracts\MailProvider;
use App\Mail\YandexMailProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiAnalyzer::class, GeminiAiAnalyzer::class);
        $this->app->bind(MailProvider::class, YandexMailProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
