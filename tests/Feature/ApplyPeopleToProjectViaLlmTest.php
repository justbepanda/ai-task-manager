<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\ApplyPeopleToProjectViaLlm;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Executor;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
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
            'manager_executor_id' => null,
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
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

        $result = app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project);

        $this->assertTrue($result['applied']);
        $project->refresh()->load(['managerExecutor', 'executors']);
        $this->assertSame($manager->id, $project->manager_executor_id);
        $this->assertSame('Менеджер', $manager->fresh()->position);
        $this->assertTrue($project->executors->contains('id', $assignee->id));
        $this->assertTrue($project->executors->contains('id', $manager->id));

        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $message->id,
            'step' => 'llm_people_applied',
            'status' => 'ok',
        ]);
    }

    public function test_creates_executor_from_proposed_name_and_does_not_overwrite_manager(): void
    {
        $existingManager = Executor::factory()->create([
            'last_name' => 'Уже',
            'first_name' => 'Менеджер',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'manager_executor_id' => $existingManager->id,
        ]);
        $project->executors()->syncWithoutDetaching([$existingManager->id]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
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

        app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project);

        $project->refresh()->load('executors');
        $this->assertSame($existingManager->id, $project->manager_executor_id);
        $this->assertTrue(
            $project->executors->contains(fn (Executor $e) => str_contains($e->fullName(), 'Исполнитель')
                || str_contains($e->fullName(), 'Новый')),
        );
        $this->assertGreaterThanOrEqual(2, Executor::query()->count());
    }

    public function test_skips_ignored_messages(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Ignored,
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'manager_executor_id' => 1,
            'assignee_executor_ids' => [],
            'assignee_names' => [],
            'confidence' => 1,
            'reason' => 'should not run',
        ]));

        $result = app(ApplyPeopleToProjectViaLlm::class)->handle($message, $project);

        $this->assertFalse($result['applied']);
        $this->assertSame(0, ProcessingLog::query()->where('step', 'llm_people_applied')->count());
    }
}
