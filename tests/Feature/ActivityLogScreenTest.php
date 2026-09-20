<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Activity\ActivityLogRecorder;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\ApplyMailFiltersJob;
use App\Models\ActivityLog;
use App\Models\MailFilterSetting;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityLogScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recorder_writes_activity_log(): void
    {
        $log = app(ActivityLogRecorder::class)->record(
            category: 'llm',
            action: 'assign_suggest',
            status: 'ok',
            summary: 'Тест',
            details: ['groups' => 1],
        );

        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'category' => 'llm',
            'action' => 'assign_suggest',
            'status' => 'ok',
            'summary' => 'Тест',
        ]);
    }

    #[Test]
    public function apply_mail_filters_job_writes_activity_log(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['noreply@'],
            'blocked_domains' => [],
            'subject_stop_words' => [],
            'body_stop_words' => [],
        ]);

        Message::factory()->create([
            'sender' => 'noreply@example.com',
            'subject' => 'x',
            'body' => 'y',
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
        ]);

        $this->app->call([new ApplyMailFiltersJob(userId: null), 'handle']);

        $this->assertDatabaseHas('activity_logs', [
            'category' => 'mail_filters',
            'action' => 'rebuild_ignored',
            'status' => 'ok',
        ]);

        $log = ActivityLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->details['touched'] ?? null);
    }

    #[Test]
    public function admin_can_open_activity_log_screen(): void
    {
        $admin = $this->makeAdmin();

        app(ActivityLogRecorder::class)->record(
            category: 'mail_filters',
            action: 'rebuild_ignored',
            status: 'ok',
            summary: 'Пересборка игнорируемых: изменено писем — 2',
        );

        ProcessingLog::query()->create([
            'message_id' => Message::factory()->create()->id,
            'step' => 'ai_analyzed',
            'status' => 'ok',
            'details' => ['model' => 'fake'],
        ]);

        $this->screen('platform.activity-logs')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Журнал')
            ->assertSee('Пересборка игнорируемых')
            ->assertSee('AI-анализ');
    }

    #[Test]
    public function rebuild_ignored_button_dispatches_job_with_user(): void
    {
        Queue::fake([ApplyMailFiltersJob::class]);
        $admin = $this->makeAdmin();

        $this->screen('platform.mail-filters')
            ->actingAs($admin)
            ->method('rebuildIgnored')
            ->assertOk();

        Queue::assertPushed(ApplyMailFiltersJob::class, function (ApplyMailFiltersJob $job) use ($admin): bool {
            return $job->userId === $admin->id;
        });
    }
}
