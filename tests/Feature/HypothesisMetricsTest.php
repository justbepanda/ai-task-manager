<?php

namespace Tests\Feature;

use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Domain\Metrics\HypothesisMetricsReport;
use App\Domain\ManagerActions\ManagerActionService;
use App\Enums\EventType;
use App\Enums\MailSource;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Mail\DTO\NormalizedIncomingMessage;
use App\Mail\Services\PersistIncomingMessage;
use App\Models\Event;
use App\Models\HypothesisMetricEvent;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HypothesisMetricsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recorder_persists_metric_events(): void
    {
        $message = Message::factory()->create();

        app(HypothesisMetricsRecorder::class)->record('mail_received', [
            'source' => 'yandex',
        ], $message);

        $this->assertDatabaseHas('hypothesis_metric_events', [
            'name' => 'mail_received',
            'message_id' => $message->id,
        ]);
        $this->assertSame(1, HypothesisMetricEvent::query()->count());
    }

    #[Test]
    public function persist_incoming_message_records_mail_received(): void
    {
        $incoming = new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: 'metrics-1@example.com',
            sender: 'client@example.com',
            recipients: ['me@yandex.ru'],
            cc: [],
            subject: 'Hello',
            body: 'Body',
            receivedAt: Carbon::now(),
            headers: ['message-id' => '<metrics-1@example.com>'],
            metadata: ['uid' => 1],
            rawHeaders: 'From: client@example.com',
            rawBody: 'Body',
        );

        app(PersistIncomingMessage::class)->handle($incoming);

        $this->assertDatabaseHas('hypothesis_metric_events', [
            'name' => 'mail_received',
        ]);
    }

    #[Test]
    public function approve_without_edit_records_draft_approved_unchanged(): void
    {
        $action = $this->pendingAction('Draft text');

        app(ManagerActionService::class)->approve($action);

        $this->assertDatabaseHas('hypothesis_metric_events', [
            'name' => 'draft_approved_unchanged',
            'manager_action_id' => $action->id,
        ]);
        $this->assertSame(0, HypothesisMetricEvent::query()->where('name', 'draft_edited')->count());
    }

    #[Test]
    public function edit_then_approve_records_draft_edited_not_unchanged(): void
    {
        $action = $this->pendingAction('Old draft');

        app(ManagerActionService::class)->edit($action, 'New draft');
        app(ManagerActionService::class)->approve($action->fresh());

        $this->assertDatabaseHas('hypothesis_metric_events', [
            'name' => 'draft_edited',
            'manager_action_id' => $action->id,
        ]);
        $this->assertSame(0, HypothesisMetricEvent::query()->where('name', 'draft_approved_unchanged')->count());
    }

    #[Test]
    public function reject_records_action_rejected(): void
    {
        $action = $this->pendingAction('Draft');

        app(ManagerActionService::class)->reject($action, 'Не нужно');

        $this->assertDatabaseHas('hypothesis_metric_events', [
            'name' => 'action_rejected',
            'manager_action_id' => $action->id,
        ]);
    }

    #[Test]
    public function hypothesis_report_command_prints_russian_metrics(): void
    {
        Message::factory()->create([
            'classification' => MessageClassification::Client,
        ]);
        app(HypothesisMetricsRecorder::class)->record('mail_received');

        $this->artisan('metrics:hypothesis-report')
            ->expectsOutputToContain('Метрики гипотезы')
            ->expectsOutputToContain('Получено писем')
            ->assertSuccessful();

        $this->artisan('metrics:hypothesis-report', ['--json' => true])
            ->assertSuccessful();

        $this->artisan('mailbox:evaluate')
            ->expectsOutputToContain('Phase 8')
            ->assertSuccessful();
    }

    #[Test]
    public function report_snapshot_includes_labels(): void
    {
        $snapshot = app(HypothesisMetricsReport::class)->snapshot();

        $this->assertArrayHasKey('labels', $snapshot);
        $this->assertArrayHasKey('from_events', $snapshot);
        $this->assertArrayHasKey('Получено писем', $snapshot['labels']);
    }

    #[Test]
    public function message_view_shows_processing_journal(): void
    {
        $admin = $this->makeAdmin();
        $message = Message::factory()->create([
            'subject' => 'Journal message',
            'processing_status' => MessageProcessingStatus::Classified,
            'classification' => MessageClassification::Client,
        ]);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'imported',
            'status' => 'ok',
            'details' => ['source' => 'yandex', 'api_key' => 'should-not-leak'],
        ]);

        $this->screen('platform.messages.view', ['message' => $message])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Журнал обработки')
            ->assertSee('Импорт')
            ->assertSee('Исправления анализа')
            ->assertDontSee('should-not-leak');
    }

    private function pendingAction(string $draft): ManagerAction
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Applied,
            'classification' => MessageClassification::Client,
            'requires_manager' => true,
        ]);

        $event = Event::query()->create([
            'message_id' => $message->id,
            'project_id' => $project->id,
            'type' => EventType::ClientQuestion,
            'summary' => 'Summary',
            'confidence' => 0.9,
            'payload' => [],
            'applied_at' => now(),
        ]);

        return ManagerAction::query()->create([
            'project_id' => $project->id,
            'event_id' => $event->id,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::Reply,
            'status' => ManagerActionStatus::Pending,
            'reason' => 'Reason',
            'draft_reply' => $draft,
        ]);
    }
}
