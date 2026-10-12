<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Projects\ApplyRedminePeopleToProject;
use App\Enums\EventType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Hermes\ProcessIncomingMessage;
use App\Jobs\ApplyEventJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Executor;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectTask;
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

    public function test_applies_author_as_task_manager_and_assignee_as_executor(): void
    {
        $project = Project::factory()->create([
            'name' => 'Demo',
            'status' => ProjectStatus::Active,
        ]);

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::Redmine,
            'external_key' => '10',
            'title' => 'Тест',
            'status' => ProjectTaskStatus::Open,
        ]);

        $message = Message::factory()->create([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Demo - Bug #10] Тест',
            'body' => "Автор: Мария Иванова\nНазначена: Евгений Сипров\n",
            'classification' => MessageClassification::Technical,
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        app(ApplyRedminePeopleToProject::class)->handle($message, $project, $task);

        $task->refresh()->load(['managerExecutor', 'executors']);
        $project->refresh()->load('executors');

        $this->assertSame($task->manager_executor_id, $task->managerExecutor?->id);
        $this->assertSame('Мария', $task->managerExecutor?->first_name);
        $this->assertSame('Иванова', $task->managerExecutor?->last_name);
        $this->assertSame('Менеджер', $task->managerExecutor?->position);

        $taskNames = $task->executors->map(fn (Executor $e) => $e->fullName())->all();
        $this->assertTrue(collect($taskNames)->contains(fn (string $n) => str_contains($n, 'Сипров')));
        $this->assertTrue(collect($taskNames)->contains(fn (string $n) => str_contains($n, 'Иванова')));

        $projectNames = $project->executors->map(fn (Executor $e) => $e->fullName())->all();
        $this->assertTrue(collect($projectNames)->contains(fn (string $n) => str_contains($n, 'Сипров')));
    }

    public function test_applies_author_with_markdown_bullet_prefix(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::Redmine,
            'external_key' => '11',
            'title' => 'Bullet',
            'status' => ProjectTaskStatus::Open,
        ]);

        $message = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Demo - Bug #11] Тест',
            'body' => "* Автор: Пётр Петров\n* Назначена: Анна Козлова\n",
            'classification' => MessageClassification::Technical,
            'project_id' => $project->id,
            'project_task_id' => $task->id,
        ]);

        app(ApplyRedminePeopleToProject::class)->handle($message, $project, $task);

        $task->refresh()->load('managerExecutor');
        $this->assertSame('Пётр', $task->managerExecutor?->first_name);
        $this->assertSame('Петров', $task->managerExecutor?->last_name);
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
        $this->assertNotNull($message->project_task_id);

        $task = ProjectTask::query()->find($message->project_task_id);
        $this->assertNotNull($task);
        $this->assertNotNull($task->manager_executor_id);
        $this->assertTrue(
            ManagerAction::query()->where('message_id', $message->id)->where('status', 'pending')->exists()
            || $message->events()->exists(),
        );
    }
}
