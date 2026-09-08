<?php

namespace Tests\Feature;

use App\Domain\Events\ApplyProjectEventService;
use App\Enums\EventType;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ApplyEventJob;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectStateLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplyProjectEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function high_confidence_applies_event_updates_project_and_state_log(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'last_event_type' => null,
        ]);
        $message = $this->analyzedMessage($project, confidence: 0.92, requiresManager: false);
        $event = $this->makeEvent($message, $project, EventType::ClientQuestion, 0.92, [
            'requires_manager' => false,
            'suggested_action' => 'no_action',
        ]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertTrue($result['applied']);
        $this->assertFalse($result['noop']);

        $project->refresh();
        $event->refresh();
        $message->refresh();

        $this->assertSame(EventType::ClientQuestion->value, $project->last_event_type);
        $this->assertNotNull($project->last_event_at);
        $this->assertSame($event->summary, $project->last_summary);
        $this->assertNotNull($event->applied_at);
        $this->assertSame(MessageProcessingStatus::Applied, $message->processing_status);

        $this->assertDatabaseHas('project_state_logs', [
            'project_id' => $project->id,
            'event_id' => $event->id,
            'change_type' => 'event_applied',
        ]);

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'event_applied',
            'status' => 'ok',
        ]);

        $this->assertSame(0, ManagerAction::query()->count());
    }

    #[Test]
    public function low_confidence_does_not_mutate_project(): void
    {
        $project = Project::factory()->create([
            'last_event_type' => null,
            'last_summary' => null,
        ]);
        $message = $this->analyzedMessage($project, confidence: 0.40, requiresManager: true);
        $event = $this->makeEvent($message, $project, EventType::ClientRequest, 0.40, [
            'requires_manager' => true,
            'suggested_action' => 'review',
            'reason' => 'Unsure',
        ]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertFalse($result['applied']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('confidence_below_auto_apply', $result['reason']);

        $project->refresh();
        $event->refresh();
        $message->refresh();

        $this->assertNull($project->last_event_type);
        $this->assertNull($project->last_summary);
        $this->assertNull($event->applied_at);
        $this->assertSame(MessageProcessingStatus::NeedsManual, $message->processing_status);
        $this->assertSame(0, ProjectStateLog::query()->count());

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'event_apply_skipped',
            'status' => 'skipped',
        ]);

        $action = ManagerAction::query()->first();
        $this->assertNotNull($action);
        $this->assertSame(ManagerActionType::Review, $action->action_type);
        $this->assertSame(ManagerActionStatus::Pending, $action->status);
    }

    #[Test]
    public function creates_manager_action_when_requires_manager(): void
    {
        $project = Project::factory()->create();
        $message = $this->analyzedMessage($project, confidence: 0.95, requiresManager: true);
        $event = $this->makeEvent($message, $project, EventType::ClientReportedBug, 0.95, [
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Добрый день! Смотрим проблему.',
            'reason' => 'Клиент ждёт ответ',
        ]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertTrue($result['applied']);
        $this->assertNotNull($result['manager_action_id']);

        $action = ManagerAction::query()->findOrFail($result['manager_action_id']);
        $this->assertSame($event->id, $action->event_id);
        $this->assertSame($project->id, $action->project_id);
        $this->assertSame(ManagerActionType::Reply, $action->action_type);
        $this->assertSame(ManagerActionStatus::Pending, $action->status);
        $this->assertSame('Добрый день! Смотрим проблему.', $action->draft_reply);
        $this->assertSame('Клиент ждёт ответ', $action->reason);
    }

    #[Test]
    public function does_not_duplicate_pending_manager_action_for_same_event(): void
    {
        $project = Project::factory()->create();
        $message = $this->analyzedMessage($project, confidence: 0.9, requiresManager: true);
        $event = $this->makeEvent($message, $project, EventType::ClientQuestion, 0.9, [
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Draft',
        ]);

        $service = app(ApplyProjectEventService::class);
        $first = $service->handle($event);
        $second = $service->handle($event->fresh());

        $this->assertTrue($first['applied']);
        $this->assertTrue($second['noop']);
        $this->assertSame(1, ManagerAction::query()->count());
        $this->assertSame($first['manager_action_id'], ManagerAction::query()->first()->id);
    }

    #[Test]
    public function already_applied_event_is_noop(): void
    {
        $project = Project::factory()->create(['last_summary' => 'Old']);
        $message = $this->analyzedMessage($project, confidence: 0.9, requiresManager: false, extra: [
            'processing_status' => MessageProcessingStatus::Applied,
        ]);
        $event = $this->makeEvent($message, $project, EventType::TaskCompleted, 0.9, [
            'requires_manager' => false,
            'suggested_action' => 'no_action',
        ]);
        $event->update(['applied_at' => now()]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertTrue($result['noop']);
        $this->assertSame('already_applied', $result['reason']);
        $this->assertSame('Old', $project->fresh()->last_summary);
        $this->assertSame(0, ProjectStateLog::query()->count());
        $this->assertSame(0, ManagerAction::query()->count());
    }

    #[Test]
    public function apply_event_job_calls_service(): void
    {
        $project = Project::factory()->create();
        $message = $this->analyzedMessage($project, confidence: 0.88, requiresManager: false);
        $event = $this->makeEvent($message, $project, EventType::InformationOnly, 0.88, [
            'requires_manager' => false,
            'suggested_action' => 'no_action',
        ]);

        (new ApplyEventJob($event->id))->handle(app(ApplyProjectEventService::class));

        $this->assertNotNull($event->fresh()->applied_at);
        $this->assertSame(MessageProcessingStatus::Applied, $message->fresh()->processing_status);
        $this->assertSame(EventType::InformationOnly->value, $project->fresh()->last_event_type);
    }

    #[Test]
    public function apply_event_job_can_be_dispatched_on_bus(): void
    {
        Bus::fake();

        ApplyEventJob::dispatch(42);

        Bus::assertDispatched(ApplyEventJob::class, fn (ApplyEventJob $job) => $job->eventId === 42);
    }

    #[Test]
    public function paused_project_becomes_active_on_client_bug(): void
    {
        $project = Project::factory()->paused()->create();
        $message = $this->analyzedMessage($project, confidence: 0.9, requiresManager: true);
        $event = $this->makeEvent($message, $project, EventType::ClientReportedBug, 0.9, [
            'requires_manager' => true,
            'suggested_action' => 'follow_up',
        ]);

        app(ApplyProjectEventService::class)->handle($event);

        $this->assertSame(ProjectStatus::Active, $project->fresh()->status);
    }

    #[Test]
    public function archived_project_is_not_unarchived(): void
    {
        $project = Project::factory()->archived()->create();
        $message = $this->analyzedMessage($project, confidence: 0.9, requiresManager: false);
        $event = $this->makeEvent($message, $project, EventType::ClientQuestion, 0.9, [
            'requires_manager' => false,
            'suggested_action' => 'no_action',
        ]);

        app(ApplyProjectEventService::class)->handle($event);

        $this->assertSame(ProjectStatus::Archived, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->last_event_type);
    }

    #[Test]
    public function no_project_id_skips_apply_but_may_create_manager_action(): void
    {
        $message = Message::factory()->create([
            'project_id' => null,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'classification' => MessageClassification::Client,
            'ai_confidence' => 0.9,
            'requires_manager' => true,
            'ai_summary' => 'Unknown project',
        ]);

        $event = Event::query()->create([
            'message_id' => $message->id,
            'project_id' => null,
            'type' => EventType::ClientQuestion,
            'summary' => 'Question without project',
            'confidence' => 0.9,
            'payload' => [
                'requires_manager' => true,
                'suggested_action' => 'review',
            ],
            'applied_at' => null,
        ]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertTrue($result['skipped']);
        $this->assertSame('no_project_id', $result['reason']);
        $this->assertNull($event->fresh()->applied_at);
        $this->assertSame(MessageProcessingStatus::NeedsManual, $message->fresh()->processing_status);
        $this->assertSame(1, ManagerAction::query()->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function analyzedMessage(
        Project $project,
        float $confidence,
        bool $requiresManager,
        array $extra = [],
    ): Message {
        return Message::factory()->create(array_merge([
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'classification' => MessageClassification::Client,
            'ai_confidence' => $confidence,
            'requires_manager' => $requiresManager,
            'ai_summary' => 'Summary',
            'sender' => 'client@acme.com',
            'recipients' => ['manager@yandex.ru'],
            'subject' => 'Test',
            'body' => 'Body',
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeEvent(
        Message $message,
        Project $project,
        EventType $type,
        float $confidence,
        array $payload,
    ): Event {
        return Event::query()->create([
            'message_id' => $message->id,
            'project_id' => $project->id,
            'type' => $type,
            'summary' => 'Event summary for '.$type->value,
            'confidence' => $confidence,
            'payload' => array_merge([
                'event_type' => $type->value,
                'summary' => 'Event summary for '.$type->value,
                'confidence' => $confidence,
                'draft_reply' => null,
                'reason' => null,
            ], $payload),
            'applied_at' => null,
        ]);
    }
}
