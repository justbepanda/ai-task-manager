<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\ManagerActions\DetectClientFollowUps;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectClientFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_follow_up_for_silent_client_conversation(): void
    {
        config([
            'ai.follow_up.enabled' => true,
            'ai.follow_up.silence_days' => 3,
            'ai.follow_up.limit_per_run' => 10,
        ]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Сроки',
            'external_thread_id' => fake()->uuid(),
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Applied,
            'subject' => 'Когда будет готово?',
            'received_at' => now()->subDays(5),
        ]);

        $result = app(DetectClientFollowUps::class)->handle();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('manager_actions', [
            'message_id' => $message->id,
            'project_id' => $project->id,
            'action_type' => ManagerActionType::FollowUp->value,
            'status' => ManagerActionStatus::Pending->value,
        ]);
    }

    public function test_does_not_duplicate_follow_up(): void
    {
        config([
            'ai.follow_up.enabled' => true,
            'ai.follow_up.silence_days' => 3,
        ]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'X',
            'external_thread_id' => fake()->uuid(),
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'received_at' => now()->subDays(10),
        ]);

        ManagerAction::query()->create([
            'project_id' => $project->id,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::FollowUp,
            'status' => ManagerActionStatus::Pending,
            'reason' => 'already',
        ]);

        $result = app(DetectClientFollowUps::class)->handle();

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, ManagerAction::query()->where('message_id', $message->id)->count());
    }

    public function test_skips_when_newer_message_exists(): void
    {
        config([
            'ai.follow_up.enabled' => true,
            'ai.follow_up.silence_days' => 3,
        ]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'X',
            'external_thread_id' => fake()->uuid(),
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'received_at' => now()->subDays(10),
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'received_at' => now()->subDay(),
        ]);

        $result = app(DetectClientFollowUps::class)->handle();

        $this->assertSame(0, $result['created']);
    }
}
