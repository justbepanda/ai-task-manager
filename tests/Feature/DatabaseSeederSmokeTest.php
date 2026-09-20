<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function database_seeder_runs_and_creates_admin_and_mail_filters(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'developer@kelnik.ru',
        ]);

        $this->assertDatabaseHas('mail_filter_settings', [
            'id' => 1,
        ]);

        $this->assertDatabaseHas('app_settings', [
            'id' => 1,
            'auto_create_projects' => 1,
            'auto_assign_projects_llm' => 1,
        ]);

        $settings = \App\Models\MailFilterSetting::query()->find(1);
        $this->assertNotNull($settings);
        $this->assertContains('noreply@', $settings->blocked_emails);
        $this->assertContains('id.yandex.com', $settings->blocked_domains);
        $this->assertContains(
            \Database\Seeders\MailFilterSettingSeeder::REDMINE_PODDERZHKA_OCENKA_REGEX,
            $settings->subject_stop_words,
        );

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, \App\Models\User::query()->where('email', 'developer@kelnik.ru')->count());
        $this->assertSame(1, \App\Models\MailFilterSetting::query()->count());
        $this->assertSame(1, \App\Models\AppSetting::query()->count());
    }
}
