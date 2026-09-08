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
    public function database_seeder_runs_and_creates_admin_and_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'developer@kelnik.ru',
        ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'Интернет-магазин А',
        ]);

        $this->assertDatabaseHas('messages', [
            'external_id' => 'seed:msg:client-question-1',
            'source' => 'seed',
        ]);

        $this->assertDatabaseHas('manager_actions', [
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('mail_filter_settings', [
            'id' => 1,
        ]);

        $this->assertDatabaseHas('client_companies', [
            'name' => 'ООО Ромашка',
        ]);

        $this->assertDatabaseHas('executors', [
            'last_name' => 'Смирнов',
            'first_name' => 'Алексей',
        ]);

        // re-runnable
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, \App\Models\User::query()->where('email', 'developer@kelnik.ru')->count());
        $this->assertSame(1, \App\Models\Message::query()->where('external_id', 'seed:msg:client-question-1')->count());
        $this->assertSame(1, \App\Models\MailFilterSetting::query()->count());
        $this->assertSame(1, \App\Models\ClientCompany::query()->where('name', 'ООО Ромашка')->count());
        $this->assertSame(1, \App\Models\Executor::query()->where('last_name', 'Смирнов')->where('first_name', 'Алексей')->count());
    }
}
