<?php

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Hermes\Contracts\HermesAgent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HermesAgentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function hermes_propose_uses_llm_and_logs_steps(): void
    {
        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => null,
            'confidence' => 0.9,
            'event_type' => 'client_question',
            'summary' => 'Вопрос',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Ответ',
            'reason' => 'test',
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Subj',
            'external_thread_id' => fake()->uuid(),
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => 'Hello',
            'body' => 'Body',
            'sender' => 'a@b.c',
        ]);

        $proposal = app(HermesAgent::class)->propose($message);

        $this->assertSame('Вопрос', $proposal->payload['summary']);
        $this->assertSame($message->id, $proposal->context->message['id']);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'hermes_context',
            'status' => 'ok',
        ]);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'hermes_proposed',
            'status' => 'ok',
        ]);
        $this->assertSame(2, ProcessingLog::query()->where('message_id', $message->id)->count());
    }
}
