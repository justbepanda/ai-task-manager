<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ops\AdminArtisanCatalog;
use App\Domain\Ops\RunAdminArtisanAction;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminToolsScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function catalog_contains_core_commands(): void
    {
        $ids = array_column(AdminArtisanCatalog::actions(), 'id');

        $this->assertContains('mail_sync', $ids);
        $this->assertContains('reprocess', $ids);
        $this->assertContains('apply_mail_filters', $ids);
        $this->assertContains('detect_follow_ups_sync', $ids);
        $this->assertContains('metrics_report', $ids);
        $this->assertContains('db_fresh_seed', $ids);

        $fresh = AdminArtisanCatalog::find('db_fresh_seed');
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh['danger']);
        $this->assertSame('migrate:fresh', $fresh['command']);
        $this->assertTrue($fresh['options']['--seed'] ?? false);
        $this->assertTrue($fresh['options']['--force'] ?? false);
    }

    #[Test]
    public function admin_can_open_tools_screen(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.admin-tools')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Сервисные команды')
            ->assertSee('messages:reprocess')
            ->assertSee('Пересобрать игнорируемые')
            ->assertSee('Скан тишины')
            ->assertSee('Очистить БД + seed');
    }

    #[Test]
    public function running_tool_writes_activity_log(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $options): bool {
                return $command === 'metrics:hypothesis-report' && $options === [];
            })
            ->andReturnUsing(function ($command, $options, $output): int {
                $output->writeln('Метрики гипотезы (§21)');

                return 0;
            });

        $result = app(RunAdminArtisanAction::class)->handle('metrics_report');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Метрики', $result['output']);
        $this->assertDatabaseHas('activity_logs', [
            'category' => 'system',
            'action' => 'admin_tool',
            'status' => 'ok',
            'user_id' => $admin->id,
        ]);

        $log = ActivityLog::query()->latest('id')->first();
        $this->assertSame('metrics_report', $log?->details['tool_id'] ?? null);
    }

    #[Test]
    public function admin_can_post_run_method(): void
    {
        $admin = $this->makeAdmin();

        Artisan::shouldReceive('call')->andReturn(0);

        $this->screen('platform.admin-tools')
            ->actingAs($admin)
            ->method('run', ['tool_id' => 'metrics_report'])
            ->assertOk();
    }
}
