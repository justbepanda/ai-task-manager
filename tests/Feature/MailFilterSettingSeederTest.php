<?php

namespace Tests\Feature;

use App\Models\MailFilterSetting;
use Database\Seeders\MailFilterSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailFilterSettingSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_creates_default_mail_filter_settings(): void
    {
        $this->seed(MailFilterSettingSeeder::class);

        $settings = MailFilterSetting::query()->find(1);
        $this->assertNotNull($settings);
        $this->assertContains('noreply@', $settings->blocked_emails);
        $this->assertContains('id.yandex.ru', $settings->blocked_domains);
        $this->assertContains('пароль для приложения', $settings->subject_stop_words);
        $this->assertContains('Яндекс ID', $settings->body_stop_words);

        $this->seed(MailFilterSettingSeeder::class);
        $this->assertSame(1, MailFilterSetting::query()->count());
    }
}
