<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\ApplyProjectEventService;
use App\Domain\ManagerActions\SupersedeStaleConversationManagerActions;
use App\Enums\EventType;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupersedeStaleConversationManagerActionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function newer_pending_action_completes_older_ones_in_same_conversation(): void
    {
        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Перенос сайтов',
        ]);

        $old = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-20 10:00:00'));
        $newer = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-22 12:00:00'));

        $closed = app(SupersedeStaleConversationManagerActions::class)->afterCreated($newer);

        $this->assertSame(1, $closed);
        $this->assertSame(ManagerActionStatus::Completed, $old->fresh()->status);
        $this->assertSame(ManagerActionStatus::Pending, $newer->fresh()->status);
        $this->assertStringContainsString('Автоматически закрыто', (string) $old->fresh()->manager_notes);
    }

    #[Test]
    public function creating_action_for_older_message_is_itself_completed_when_newer_pending_exists(): void
    {
        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Thread',
        ]);

        $newer = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-22 12:00:00'));
        $older = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-20 10:00:00'));

        app(SupersedeStaleConversationManagerActions::class)->afterCreated($older);

        $this->assertSame(ManagerActionStatus::Pending, $newer->fresh()->status);
        $this->assertSame(ManagerActionStatus::Completed, $older->fresh()->status);
    }

    #[Test]
    public function cleanup_all_keeps_one_pending_per_conversation(): void
    {
        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'STAVNI',
        ]);

        $a = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-18'));
        $b = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-19'));
        $c = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-21'));

        $result = app(SupersedeStaleConversationManagerActions::class)->cleanupAll();

        $this->assertSame(2, $result['superseded']);
        $this->assertSame(1, $result['groups']);
        $this->assertSame(ManagerActionStatus::Completed, $a->fresh()->status);
        $this->assertSame(ManagerActionStatus::Completed, $b->fresh()->status);
        $this->assertSame(ManagerActionStatus::Pending, $c->fresh()->status);
    }

    #[Test]
    public function apply_event_supersedes_older_pending_in_conversation(): void
    {
        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'VM move',
        ]);

        $old = $this->pendingForMessage($project, $conversation, receivedAt: Carbon::parse('2026-09-20 10:00:00'));

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'classification' => MessageClassification::Client,
            'ai_confidence' => 0.95,
            'requires_manager' => true,
            'received_at' => Carbon::parse('2026-09-22 15:00:00'),
            'subject' => 'RE: VM move',
        ]);

        $event = Event::query()->create([
            'message_id' => $message->id,
            'project_id' => $project->id,
            'type' => EventType::ClientApproved,
            'summary' => 'Клиент согласовал созвон',
            'confidence' => 0.95,
            'payload' => [
                'requires_manager' => true,
                'suggested_action' => ManagerActionType::Reply->value,
                'draft_reply' => 'Договорились на вторник.',
            ],
            'applied_at' => null,
        ]);

        $result = app(ApplyProjectEventService::class)->handle($event);

        $this->assertNotNull($result['manager_action_id']);
        $this->assertSame(ManagerActionStatus::Completed, $old->fresh()->status);
        $this->assertSame(
            ManagerActionStatus::Pending,
            ManagerAction::query()->findOrFail($result['manager_action_id'])->status,
        );
    }

    #[Test]
    public function different_conversations_are_not_collapsed(): void
    {
        $project = Project::factory()->create();
        $c1 = Conversation::query()->create(['project_id' => $project->id, 'subject' => 'A']);
        $c2 = Conversation::query()->create(['project_id' => $project->id, 'subject' => 'B']);

        $a = $this->pendingForMessage($project, $c1, receivedAt: Carbon::parse('2026-09-20'));
        $b = $this->pendingForMessage($project, $c2, receivedAt: Carbon::parse('2026-09-21'));

        app(SupersedeStaleConversationManagerActions::class)->afterCreated($b);

        $this->assertSame(ManagerActionStatus::Pending, $a->fresh()->status);
        $this->assertSame(ManagerActionStatus::Pending, $b->fresh()->status);
    }

    private function pendingForMessage(
        Project $project,
        Conversation $conversation,
        Carbon $receivedAt,
    ): ManagerAction {
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'requires_manager' => true,
            'received_at' => $receivedAt,
            'subject' => $conversation->subject,
        ]);

        return ManagerAction::query()->create([
            'project_id' => $project->id,
            'event_id' => null,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::Reply,
            'status' => ManagerActionStatus::Pending,
            'reason' => 'test',
            'draft_reply' => 'draft',
        ]);
    }
}
