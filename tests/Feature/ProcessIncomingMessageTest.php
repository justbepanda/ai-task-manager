<?php

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Enums\EventType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Hermes\ProcessIncomingMessage;
use App\Jobs\ApplyEventJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function full_hermes_pipeline_classifies_matches_and_analyzes(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        ProjectMatchingRule::query()->create([
            'project_id' => $project->id,
            'client_emails' => ['client@acme.com'],
            'staff_emails' => null,
            'domains' => null,
            'subject_patterns' => null,
            'keywords' => null,
            'technical_sources' => null,
            'custom_rules' => null,
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => $project->id,
            'confidence' => 0.93,
            'event_type' => EventType::ClientQuestion->value,
            'summary' => 'Вопрос клиента',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Ответим.',
            'reason' => 'test',
        ]));

        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'Q',
            'external_thread_id' => fake()->uuid(),
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'sender' => 'client@acme.com',
            'subject' => 'Help',
            'body' => 'Please',
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
        ]);

        $result = app(ProcessIncomingMessage::class)->handle($message);

        $this->assertTrue($result->usedLlm);
        $this->assertTrue($result->needsLlm);
        $this->assertNotNull($result->event);
        $this->assertSame(MessageClassification::Client, $result->message->classification);
        $this->assertSame($project->id, $result->message->project_id);
        $this->assertSame(MessageProcessingStatus::Analyzed, $result->message->processing_status);
    }
}
