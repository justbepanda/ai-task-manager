<?php

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\Exceptions\AnalysisException;
use App\AI\FakeAiAnalyzer;
use App\Enums\EventType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ApplyEventJob;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectInstruction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyzeMessageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function valid_fake_analysis_creates_event_and_dispatches_apply_job(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $fake = new FakeAiAnalyzer;
        $this->app->instance(AiAnalyzer::class, $fake);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        ProjectInstruction::query()->create([
            'project_id' => $project->id,
            'body' => 'Клиент — ООО Тест.',
        ]);

        $message = $this->classifiedMessage($project, [
            'subject' => 'Когда будет готово?',
            'body' => 'Нужны сроки по задаче.',
        ]);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();

        $this->assertSame(MessageProcessingStatus::Analyzed, $message->processing_status);
        $this->assertNotNull($message->ai_summary);
        $this->assertNotNull($message->ai_confidence);
        $this->assertTrue($message->requires_manager);

        $event = Event::query()->where('message_id', $message->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(EventType::ClientQuestion, $event->type);
        $this->assertSame($project->id, $event->project_id);
        $this->assertNull($event->applied_at);

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'ai_analyzed',
            'status' => 'ok',
        ]);

        Bus::assertDispatched(ApplyEventJob::class, fn (ApplyEventJob $job) => $job->eventId === $event->id);
    }

    #[Test]
    public function parse_error_marks_message_failed(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $fake = (new FakeAiAnalyzer)->withException(
            new AnalysisException('Gemini returned invalid JSON', 'parse_error'),
        );
        $this->app->instance(AiAnalyzer::class, $fake);

        $project = Project::factory()->create();
        $message = $this->classifiedMessage($project);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();

        $this->assertSame(MessageProcessingStatus::Failed, $message->processing_status);
        $this->assertSame('Gemini returned invalid JSON', $message->processing_error);
        $this->assertSame(0, Event::query()->count());
        Bus::assertNotDispatched(ApplyEventJob::class);

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'ai_analyzed',
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function unknown_event_type_fails_validation(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create();

        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => $project->id,
            'confidence' => 0.9,
            'event_type' => 'totally_unknown',
            'summary' => 'Summary',
            'requires_manager' => true,
            'suggested_action' => 'review',
            'draft_reply' => null,
            'reason' => null,
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->classifiedMessage($project);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();

        $this->assertSame(MessageProcessingStatus::Failed, $message->processing_status);
        $this->assertStringContainsString('event_type', (string) $message->processing_error);
        $this->assertSame(0, Event::query()->count());
        Bus::assertNotDispatched(ApplyEventJob::class);
    }

    #[Test]
    public function null_project_id_from_ai_is_ok_when_message_has_project(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create();

        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => null,
            'confidence' => 0.7,
            'event_type' => 'information_only',
            'summary' => 'Информация без вопроса',
            'requires_manager' => false,
            'suggested_action' => 'no_action',
            'draft_reply' => null,
            'reason' => 'FYI',
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->classifiedMessage($project);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();
        $event = Event::query()->first();

        $this->assertSame(MessageProcessingStatus::Analyzed, $message->processing_status);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame($project->id, $event?->project_id);
    }

    #[Test]
    public function low_confidence_is_stored_for_phase_five(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create();

        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => $project->id,
            'confidence' => 0.31,
            'event_type' => 'client_request',
            'summary' => 'Неуверенный разбор',
            'requires_manager' => true,
            'suggested_action' => 'review',
            'draft_reply' => null,
            'reason' => 'Low signal',
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->classifiedMessage($project);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();

        $this->assertSame(MessageProcessingStatus::Analyzed, $message->processing_status);
        $this->assertEqualsWithDelta(0.31, (float) $message->ai_confidence, 0.0001);
        $this->assertEqualsWithDelta(0.31, (float) Event::query()->first()->confidence, 0.0001);
    }

    #[Test]
    public function draft_reply_required_when_reply_action(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create();

        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'client',
            'project_id' => $project->id,
            'confidence' => 0.9,
            'event_type' => 'client_question',
            'summary' => 'Нужен ответ',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => '',
            'reason' => null,
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->classifiedMessage($project);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();

        $this->assertSame(MessageProcessingStatus::Failed, $message->processing_status);
        $this->assertStringContainsString('draft_reply', (string) $message->processing_error);
        Bus::assertNotDispatched(ApplyEventJob::class);
    }

    #[Test]
    public function skips_newsletter_and_already_analyzed(): void
    {
        Bus::fake([ApplyEventJob::class]);
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);

        $project = Project::factory()->create();

        $newsletter = $this->classifiedMessage($project, [
            'classification' => MessageClassification::Newsletter,
        ]);
        (new AnalyzeMessageJob($newsletter->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));
        $this->assertSame(0, Event::query()->count());

        $analyzed = $this->classifiedMessage($project, [
            'processing_status' => MessageProcessingStatus::Analyzed,
            'ai_summary' => 'Already done',
        ]);
        (new AnalyzeMessageJob($analyzed->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));
        $this->assertSame(0, Event::query()->count());
        Bus::assertNotDispatched(ApplyEventJob::class);
    }

    #[Test]
    public function does_not_overwrite_deterministic_client_classification(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create();

        $fake = (new FakeAiAnalyzer)->withPayload([
            'classification' => 'personal',
            'project_id' => $project->id,
            'confidence' => 0.99,
            'event_type' => 'client_question',
            'summary' => 'AI thinks personal',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Draft',
            'reason' => null,
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->classifiedMessage($project, [
            'classification' => MessageClassification::Client,
        ]);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $this->assertSame(MessageClassification::Client, $message->fresh()->classification);
        $this->assertSame('personal', Event::query()->first()->payload['classification']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function classifiedMessage(Project $project, array $attributes = []): Message
    {
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => $attributes['subject'] ?? 'Subject',
            'external_thread_id' => fake()->uuid(),
        ]);

        return Message::factory()->create(array_merge([
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Classified,
            'classification' => MessageClassification::Client,
            'sender' => 'client@acme.com',
            'recipients' => ['manager@yandex.ru'],
            'cc' => [],
            'subject' => 'Question',
            'body' => 'Please help',
            'metadata' => [
                'project_match' => [
                    'project_id' => $project->id,
                    'candidates' => [
                        ['project_id' => $project->id, 'score' => 10, 'reasons' => ['email']],
                    ],
                ],
            ],
        ], $attributes));
    }
}
