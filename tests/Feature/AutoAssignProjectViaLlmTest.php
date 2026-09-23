<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Enums\EventType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Hermes\ProcessIncomingMessage;
use App\Jobs\ApplyEventJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutoAssignProjectViaLlmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::query()->create([
            'id' => 1,
            'auto_create_projects' => false,
            'auto_create_confidence_threshold' => 0.95,
            'auto_assign_projects_llm' => true,
            'auto_assign_llm_confidence_threshold' => 0.90,
        ]);
    }

    public function test_assigns_existing_project_when_llm_confidence_high(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $project = Project::factory()->create([
            'name' => 'Acme Shop',
            'status' => ProjectStatus::Active,
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)
            ->withJsonPayload([
                'project_id' => $project->id,
                'proposed_name' => null,
                'proposed_aliases' => [],
                'confidence' => 0.94,
                'reason' => 'Тема про Acme',
                'unmatched' => false,
            ])
            ->withPayload([
                'classification' => 'client',
                'project_id' => $project->id,
                'confidence' => 0.9,
                'event_type' => EventType::ClientQuestion->value,
                'summary' => 'Вопрос',
                'requires_manager' => false,
                'suggested_action' => 'no_action',
                'draft_reply' => null,
                'reason' => 'test',
            ]));

        $message = $this->pendingMessage([
            'sender' => 'client@acme.com',
            'subject' => 'Вопрос по Acme Shop',
            'body' => 'Когда релиз?',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();

        $this->assertSame($project->id, $message->project_id);
        $this->assertTrue($message->metadata['project_match']['llm_auto_assigned'] ?? false);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'project_auto_assigned_llm',
            'status' => 'ok',
        ]);
    }

    public function test_creates_project_via_llm_when_proposed_name(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)
            ->withJsonPayload([
                'project_id' => null,
                'proposed_name' => 'Nova CRM',
                'proposed_aliases' => ['Nova'],
                'confidence' => 0.93,
                'reason' => 'Новый проект из переписки',
                'unmatched' => false,
            ])
            ->withPayload([
                'classification' => 'client',
                'project_id' => null,
                'confidence' => 0.9,
                'event_type' => EventType::ClientQuestion->value,
                'summary' => 'Вопрос',
                'requires_manager' => false,
                'suggested_action' => 'no_action',
                'draft_reply' => null,
                'reason' => 'test',
            ]));

        $message = $this->pendingMessage([
            'sender' => 'boss@nova.example',
            'subject' => 'Старт Nova CRM',
            'body' => 'Нужен статус по CRM',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();
        $project = Project::query()->where('name', 'Nova CRM')->first();

        $this->assertNotNull($project);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame('Автосоздан через LLM-распределение', $project->description);
    }

    public function test_skips_when_confidence_below_threshold(): void
    {
        $project = Project::factory()->create([
            'name' => 'Maybe',
            'status' => ProjectStatus::Active,
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'project_id' => $project->id,
            'proposed_name' => null,
            'proposed_aliases' => [],
            'confidence' => 0.5,
            'reason' => 'Не уверен',
            'unmatched' => false,
        ]));

        $message = $this->pendingMessage([
            'sender' => 'x@y.z',
            'subject' => 'Hmm',
            'body' => 'Body',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();

        $this->assertNull($message->project_id);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'project_auto_assigned_llm',
            'status' => 'skipped',
        ]);
    }

    public function test_redmine_auto_create_runs_before_llm_when_both_enabled(): void
    {
        AppSetting::current()->update([
            'auto_create_projects' => true,
            'auto_create_confidence_threshold' => 0.95,
            'auto_assign_projects_llm' => true,
            'auto_assign_llm_confidence_threshold' => 0.50,
        ]);

        $fake = new class extends FakeAiAnalyzer
        {
            public int $completeJsonCalls = 0;

            public function completeJson(string $systemPrompt, string $userPrompt): array
            {
                $this->completeJsonCalls++;

                return [
                    'project_id' => null,
                    'proposed_name' => 'SHOULD_NOT_CREATE',
                    'proposed_aliases' => [],
                    'confidence' => 0.99,
                    'reason' => 'should not run',
                    'unmatched' => false,
                ];
            }

            public function analyze(\App\AI\DTO\MessageContext $context): array
            {
                return [
                    'classification' => 'technical',
                    'project_id' => $context->message['project_id'] ?? null,
                    'confidence' => 0.9,
                    'event_type' => EventType::TechnicalNotification->value,
                    'summary' => 'Tech',
                    'requires_manager' => false,
                    'suggested_action' => 'no_action',
                    'draft_reply' => null,
                    'reason' => 'test',
                ];
            }
        };
        $this->app->instance(AiAnalyzer::class, $fake);

        Bus::fake([ApplyEventJob::class]);

        $message = $this->pendingMessage([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[First Redmine Co - Bug #3] X',
            'body' => 'Updated',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();
        $project = Project::query()->where('name', 'First Redmine Co')->first();

        $this->assertNotNull($project);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame('Автосоздан из темы Redmine', $project->description);
        // completeJson may still run for people-assign; project LLM-assign must not.
        $this->assertFalse($message->metadata['project_match']['llm_auto_assigned'] ?? false);
        $this->assertDatabaseMissing('processing_logs', [
            'message_id' => $message->id,
            'step' => 'project_auto_assigned_llm',
        ]);
        $this->assertSame(0, Project::query()->where('name', 'SHOULD_NOT_CREATE')->count());
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'project_auto_created',
            'status' => 'ok',
        ]);
    }

    public function test_disabled_setting_does_not_call_llm_assign(): void
    {
        AppSetting::current()->update(['auto_assign_projects_llm' => false]);

        $fake = new class extends FakeAiAnalyzer
        {
            public int $completeJsonCalls = 0;

            public function completeJson(string $systemPrompt, string $userPrompt): array
            {
                $this->completeJsonCalls++;

                return parent::completeJson($systemPrompt, $userPrompt);
            }
        };
        $this->app->instance(AiAnalyzer::class, $fake);

        $message = $this->pendingMessage([
            'sender' => 'a@b.c',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $this->assertNull($message->fresh()->project_id);
        $this->assertSame(0, $fake->completeJsonCalls);
        $this->assertSame(0, ProcessingLog::query()->where('step', 'project_auto_assigned_llm')->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pendingMessage(array $attributes): Message
    {
        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => $attributes['subject'] ?? 'Subject',
            'external_thread_id' => fake()->uuid(),
        ]);

        return Message::factory()->create(array_merge([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
            'recipients' => ['manager@yandex.ru'],
            'cc' => [],
            'headers' => [
                'from' => $attributes['sender'] ?? 'sender@example.com',
            ],
        ], $attributes));
    }
}
