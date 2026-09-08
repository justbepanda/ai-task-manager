<?php

namespace Tests\Feature;

use App\Domain\ManagerActions\ManagerActionService;
use App\Enums\EventType;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisCorrection;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManagerActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approve_sets_approved_and_copies_draft_to_final_reply(): void
    {
        $action = $this->pendingAction(draft: 'Добрый день! Проверяем.');

        app(ManagerActionService::class)->approve($action);

        $action->refresh();

        $this->assertSame(ManagerActionStatus::Approved, $action->status);
        $this->assertSame('Добрый день! Проверяем.', $action->final_reply);
        $this->assertNotNull($action->decided_at);
    }

    #[Test]
    public function approve_uses_explicit_final_reply_when_provided(): void
    {
        $action = $this->pendingAction(draft: 'Draft A');

        app(ManagerActionService::class)->approve($action, 'Final B');

        $this->assertSame('Final B', $action->fresh()->final_reply);
        $this->assertSame(ManagerActionStatus::Approved, $action->fresh()->status);
    }

    #[Test]
    public function edit_updates_draft_and_writes_analysis_correction(): void
    {
        $action = $this->pendingAction(draft: 'Старый draft');

        app(ManagerActionService::class)->edit($action, 'Новый draft', 'правила тона');

        $action->refresh();

        $this->assertSame(ManagerActionStatus::Edited, $action->status);
        $this->assertSame('Новый draft', $action->draft_reply);
        $this->assertSame('правила тона', $action->manager_notes);

        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $action->message_id,
            'field' => 'draft_reply',
            'ai_value' => 'Старый draft',
            'manager_value' => 'Новый draft',
        ]);
    }

    #[Test]
    public function reject_sets_rejected_and_records_corrections(): void
    {
        $action = $this->pendingAction(draft: 'Draft');

        app(ManagerActionService::class)->reject($action, 'Не нужно');

        $action->refresh();

        $this->assertSame(ManagerActionStatus::Rejected, $action->status);
        $this->assertSame('Не нужно', $action->manager_notes);
        $this->assertNotNull($action->decided_at);

        $this->assertSame(2, AnalysisCorrection::query()->where('message_id', $action->message_id)->count());
        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $action->message_id,
            'field' => 'suggested_action',
            'ai_value' => ManagerActionType::Reply->value,
            'manager_value' => 'rejected',
        ]);
        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $action->message_id,
            'field' => 'requires_manager',
            'ai_value' => 'true',
            'manager_value' => 'false',
        ]);
    }

    #[Test]
    public function complete_marks_approved_action_completed(): void
    {
        $action = $this->pendingAction(draft: 'Draft');
        app(ManagerActionService::class)->approve($action);

        app(ManagerActionService::class)->complete($action->fresh());

        $this->assertSame(ManagerActionStatus::Completed, $action->fresh()->status);
    }

    #[Test]
    public function cannot_approve_rejected_action(): void
    {
        $action = $this->pendingAction(draft: 'Draft');
        app(ManagerActionService::class)->reject($action);

        $this->expectException(ValidationException::class);

        app(ManagerActionService::class)->approve($action->fresh());
    }

    #[Test]
    public function cannot_complete_pending_action(): void
    {
        $action = $this->pendingAction(draft: 'Draft');

        $this->expectException(ValidationException::class);

        app(ManagerActionService::class)->complete($action);
    }

    private function pendingAction(string $draft, string $reason = 'Клиент ожидает ответ'): ManagerAction
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Applied,
            'classification' => MessageClassification::Client,
            'requires_manager' => true,
            'ai_summary' => 'Summary',
        ]);

        $event = Event::query()->create([
            'message_id' => $message->id,
            'project_id' => $project->id,
            'type' => EventType::ClientQuestion,
            'summary' => 'Клиент спрашивает о сроках',
            'confidence' => 0.9,
            'payload' => [
                'suggested_action' => 'reply',
                'draft_reply' => $draft,
            ],
            'applied_at' => now(),
        ]);

        return ManagerAction::query()->create([
            'project_id' => $project->id,
            'event_id' => $event->id,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::Reply,
            'status' => ManagerActionStatus::Pending,
            'reason' => $reason,
            'draft_reply' => $draft,
        ]);
    }
}
