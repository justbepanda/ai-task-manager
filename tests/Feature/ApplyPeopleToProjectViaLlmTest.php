<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\ApplyPeopleToProjectViaLlm;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Executor;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyPeopleToProjectViaLlmTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_manager_and_assignee_from_llm_payload(): void
    {
        $manager = Executor::factory()->create([
            'last_name' => 'Плашиннов',
            'first_name' => 'Константин',
            'position' => null,
            'is_active' => true,
        ]);
        $assignee = Executor::factory()->create([
            'last_name' => 'Сипров',
            'first_name' => 'Евгений',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::EmailThread,
            'external_key' => 'conv-1',
            'title' => 'Планировки',
            'status' => ProjectTaskStatus::Open,
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => 'По планировкам',
            'body' => 'Костя ведёт проект, Евгений сделает правки.',
            'sender' => 'client@example.com',
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'manager_executor_id' => $manager->id,
            'manager_name' => null,
            'assignee_executor_ids' => [$assignee->id],
            'assignee_names' => [],
            'confidence' => 0.92,
            'reason' => 'Упомянуты менеджер и исполнитель',
        ]));

        $result = app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project, $task);

        $this->assertTrue($result['applied']);
        $task->refresh()->load(['managerExecutor', 'executors']);
        $project->refresh()->load('executors');
        $this->assertSame($manager->id, $task->manager_executor_id);
        $this->assertSame('Менеджер', $manager->fresh()->position);
        $this->assertTrue($task->executors->contains('id', $assignee->id));
        $this->assertTrue($task->executors->contains('id', $manager->id));
        $this->assertTrue($project->executors->contains('id', $assignee->id));

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'llm_people_applied',
            'status' => 'ok',
        ]);
    }

    public function test_creates_executor_from_proposed_name_and_does_not_overwrite_task_manager(): void
    {
        $existingManager = Executor::factory()->create([
            'last_name' => 'Уже',
            'first_name' => 'Менеджер',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::Redmine,
            'external_key' => '42',
            'title' => 'Bug',
            'status' => ProjectTaskStatus::Open,
            'manager_executor_id' => $existingManager->id,
        ]);
        $task->executors()->syncWithoutDetaching([$existingManager->id]);
        $project->executors()->syncWithoutDetaching([$existingManager->id]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => 'Bug',
            'body' => 'Назначен: Новый Исполнитель',
            'sender' => 'dev@agency.local',
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'manager_executor_id' => null,
            'manager_name' => 'Другой Менеджер',
            'assignee_executor_ids' => [],
            'assignee_names' => ['Новый Исполнитель'],
            'confidence' => 0.8,
            'reason' => 'Новый исполнитель из письма',
        ]));

        app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project, $task);

        $task->refresh()->load('executors');
        $this->assertSame($existingManager->id, $task->manager_executor_id);
        $this->assertTrue(
            $task->executors->contains(fn (Executor $e) => str_contains($e->fullName(), 'Исполнитель')
                || str_contains($e->fullName(), 'Новый')),
        );
        $this->assertGreaterThanOrEqual(2, Executor::query()->count());
    }

    public function test_skips_ignored_messages(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::EmailThread,
            'external_key' => 'x',
            'status' => ProjectTaskStatus::Open,
        ]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'classification' => MessageClassification::Ignored,
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'manager_executor_id' => 1,
            'assignee_executor_ids' => [],
            'assignee_names' => [],
            'confidence' => 1,
            'reason' => 'should not run',
        ]));

        $result = app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project, $task);

        $this->assertFalse($result['applied']);
        $this->assertSame(0, ProcessingLog::query()->where('step', 'llm_people_applied')->count());
    }
}
