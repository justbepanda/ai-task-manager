<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\ApplyRedminePeopleToProject;
use App\Enums\EventType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Hermes\ProcessIncomingMessage;
use App\Jobs\ApplyEventJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Executor;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RedminePeopleAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::query()->create([
            'id' => 1,
            'auto_create_projects' => true,
            'auto_create_confidence_threshold' => 0.95,
            'auto_assign_projects_llm' => false,
            'auto_assign_llm_confidence_threshold' => 0.9,
        ]);
    }

    public function test_applies_author_as_manager_and_assignee_as_executor(): void
    {
        $project = Project::factory()->create([
            'name' => 'Demo',
            'status' => ProjectStatus::Active,
        ]);

        $message = Message::factory()->create([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Demo - Bug #10] Тест',
            'body' => "Автор: Мария Иванова\nНазначена: Евгений Сипров\n",
            'classification' => MessageClassification::Technical,
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        app(ApplyRedminePeopleToProject::class)->handle($message, $project);

        $project->refresh()->load(['managerExecutor', 'executors']);

        $this->assertNotNull($project->manager_executor_id);
        $this->assertSame('Мария', $project->managerExecutor?->first_name);
        $this->assertSame('Иванова', $project->managerExecutor?->last_name);
        $this->assertSame('Менеджер', $project->managerExecutor?->position);

        $names = $project->executors->map(fn (Executor $e) => $e->fullName())->all();
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, 'Сипров')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, 'Иванова')));
    }

    public function test_feedback_subject_sets_requires_manager_on_pipeline(): void
    {
        Bus::fake([ApplyEventJob::class]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withPayload([
            'classification' => 'technical',
            'project_id' => null,
            'confidence' => 0.9,
            'event_type' => EventType::TechnicalNotification->value,
            'summary' => 'Нужна обратная связь',
            'requires_manager' => false,
            'suggested_action' => 'no_action',
            'draft_reply' => null,
            'reason' => 'test',
        ]));

        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'S',
            'external_thread_id' => fake()->uuid(),
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[ЖК Витязь - New #99] (Обратная связь) Вопрос',
            'body' => "Автор: Олег Смирнов\nНазначена: Анна Козлова\nОписание: нужно ответить клиенту\n",
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
            'project_id' => null,
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ProcessIncomingMessage::class));

        $message->refresh();
        $project = Project::query()->where('name', 'ЖК Витязь')->first();

        $this->assertNotNull($project);
        $this->assertSame($project->id, $message->project_id);
        $this->assertTrue($message->requires_manager);
        $this->assertNotNull($project->fresh()->manager_executor_id);
        $this->assertTrue(
            ManagerAction::query()->where('message_id', $message->id)->where('status', 'pending')->exists()
            || $message->events()->exists(),
        );
    }
}
