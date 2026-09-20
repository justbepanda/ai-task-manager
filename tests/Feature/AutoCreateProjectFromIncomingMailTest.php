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
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutoCreateProjectFromIncomingMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withPayload([
            'classification' => 'technical',
            'project_id' => null,
            'confidence' => 0.9,
            'event_type' => EventType::TechnicalNotification->value,
            'summary' => 'Техническое уведомление',
            'requires_manager' => false,
            'suggested_action' => 'no_action',
            'draft_reply' => null,
            'reason' => 'test',
        ]));

        AppSetting::query()->create([
            'id' => 1,
            'auto_create_projects' => true,
            'auto_create_confidence_threshold' => 0.95,
        ]);
    }

    public function test_creates_project_from_redmine_subject_when_enabled(): void
    {
        Bus::fake([ApplyEventJob::class, AnalyzeMessageJob::class]);

        $this->assertSame(0, Project::query()->count());

        $message = $this->pendingMessage([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Nova Portal, Нова - Bug #42] Ошибка входа',
            'body' => 'Issue updated',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();
        $project = Project::query()->where('name', 'Nova Portal')->first();

        $this->assertNotNull($project);
        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame(MessageClassification::Technical, $message->classification);
        $this->assertTrue($message->metadata['project_match']['auto_created'] ?? false);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'project_auto_created',
            'status' => 'ok',
        ]);
        $this->assertContains(
            'Nova Portal',
            $project->matchingRule?->keywords ?? [],
        );
    }

    public function test_does_not_create_when_setting_disabled(): void
    {
        AppSetting::current()->update(['auto_create_projects' => false]);

        $message = $this->pendingMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Disabled Auto - Bug #1] Test',
            'body' => 'Body',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();

        $this->assertNull($message->project_id);
        $this->assertSame(0, Project::query()->count());
        $this->assertSame(0, ProcessingLog::query()->where('step', 'project_auto_created')->count());
    }

    public function test_does_not_create_when_confidence_below_threshold(): void
    {
        AppSetting::current()->update([
            'auto_create_projects' => true,
            'auto_create_confidence_threshold' => 0.99,
        ]);

        config(['classification.redmine_subject_project.match_score' => 0.97]);

        $message = $this->pendingMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Low Confidence Co - Bug #9] X',
            'body' => 'Body',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();

        $this->assertNull($message->project_id);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_attaches_existing_project_by_alias_without_duplicate(): void
    {
        $existing = app(\App\Domain\Projects\ProjectService::class)->create([
            'name' => 'Tekta Main',
            'status' => ProjectStatus::Active,
            'keywords' => "Tekta\nТекта",
            'technical_sources' => 'redmine',
        ]);

        $message = $this->pendingMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта, ЛК - Bug #7] Ошибка',
            'body' => 'Body',
        ]);

        // Matcher may already attach via keyword — either way no second project.
        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();

        $this->assertSame(1, Project::query()->count());
        $this->assertSame($existing->id, $message->project_id);
        $this->assertSame(0, ProcessingLog::query()->where('step', 'project_auto_created')->count());
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
