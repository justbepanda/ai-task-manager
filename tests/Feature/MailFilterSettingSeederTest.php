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
        $this->assertContains('id.yandex.com', $settings->blocked_domains);
        $this->assertContains('пароль для приложения', $settings->subject_stop_words);
        $this->assertNotContains('Заявка на обратный звонок', $settings->subject_stop_words);
        $this->assertNotContains('Заказ обратного звонка', $settings->subject_stop_words);
        $this->assertNotContains('Заявка на бронирование', $settings->subject_stop_words);
        $this->assertNotContains('Заявка на рассрочку', $settings->subject_stop_words);
        $this->assertNotContains('Заявка на обратную связь', $settings->subject_stop_words);
        $this->assertContains('ЛК Брокера, регистрация', $settings->subject_stop_words);
        $this->assertContains('calendar.yandex.ru', $settings->blocked_domains);
        $this->assertContains('360.yandex.ru', $settings->blocked_domains);
        $this->assertContains(
            MailFilterSettingSeeder::REDMINE_PODDERZHKA_OCENKA_REGEX,
            $settings->subject_stop_words,
        );
        $this->assertNotContains('Оценка:', $settings->subject_stop_words);
        $this->assertContains('Яндекс ID', $settings->body_stop_words);

        $this->seed(MailFilterSettingSeeder::class);
        $this->assertSame(1, MailFilterSetting::query()->count());
    }

    #[Test]
    public function seeder_merges_defaults_without_dropping_custom_entries(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['custom@example.com'],
            'blocked_domains' => ['custom.example'],
            'subject_stop_words' => ['Моё слово', 'Оценка:', 'Заявка на обратный звонок'],
            'body_stop_words' => [],
        ]);

        $this->seed(MailFilterSettingSeeder::class);

        $settings = MailFilterSetting::query()->find(1);
        $this->assertContains('custom@example.com', $settings->blocked_emails);
        $this->assertContains('noreply@', $settings->blocked_emails);
        $this->assertContains('custom.example', $settings->blocked_domains);
        $this->assertContains('id.yandex.com', $settings->blocked_domains);
        $this->assertContains('Моё слово', $settings->subject_stop_words);
        $this->assertContains(
            MailFilterSettingSeeder::REDMINE_PODDERZHKA_OCENKA_REGEX,
            $settings->subject_stop_words,
        );
        $this->assertNotContains('Оценка:', $settings->subject_stop_words);
        $this->assertNotContains('Заявка на обратный звонок', $settings->subject_stop_words);
    }
}
