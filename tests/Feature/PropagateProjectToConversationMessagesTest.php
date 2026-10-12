<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Conversations\PropagateProjectToConversationMessages;
use App\Domain\Messages\MessageManualOverrideService;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PropagateProjectToConversationMessagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assigning_project_to_one_message_attaches_unassigned_siblings(): void
    {
        Bus::fake([AnalyzeMessageJob::class]);

        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'Thread',
        ]);

        $older = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'subject' => 'First',
        ]);

        $newer = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => 'Re: First',
        ]);

        $newer->project_id = $project->id;
        $newer->save();

        $result = app(PropagateProjectToConversationMessages::class)->handle($newer, (int) $project->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame($project->id, $older->fresh()->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $older->fresh()->processing_status);
        $this->assertSame($project->id, $conversation->fresh()->project_id);

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $older->id,
            'step' => 'project_matched',
            'status' => 'ok',
        ]);

        $log = ProcessingLog::query()
            ->where('message_id', $older->id)
            ->where('step', 'project_matched')
            ->latest('id')
            ->first();
        $this->assertSame('conversation_propagate', $log?->details['reason'] ?? null);

        Bus::assertDispatched(AnalyzeMessageJob::class, fn (AnalyzeMessageJob $job) => $job->messageId === $older->id);
    }

    #[Test]
    public function does_not_overwrite_message_already_on_another_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => $projectA->id,
            'subject' => 'Mixed',
        ]);

        $other = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $projectA->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $source = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $projectB->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $result = app(PropagateProjectToConversationMessages::class)->handle($source, (int) $projectB->id);

        $this->assertSame(0, $result['updated']);
        $this->assertSame($projectA->id, $other->fresh()->project_id);
        $this->assertSame($projectA->id, $conversation->fresh()->project_id);
    }

    #[Test]
    public function manual_override_propagates_to_needs_manual_siblings(): void
    {
        Bus::fake([AnalyzeMessageJob::class]);

        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'Manual',
        ]);

        $stuck = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
        ]);

        $current = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::NeedsManual,
        ]);

        app(MessageManualOverrideService::class)->apply($current, [
            'project_id' => $project->id,
        ]);

        $this->assertSame($project->id, $stuck->fresh()->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $stuck->fresh()->processing_status);
    }

    #[Test]
    public function ignored_siblings_are_not_attached(): void
    {
        $project = Project::factory()->create();
        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'With ignored',
        ]);

        $ignored = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Ignored,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $source = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        app(PropagateProjectToConversationMessages::class)->handle($source, (int) $project->id);

        $this->assertNull($ignored->fresh()->project_id);
    }
}
