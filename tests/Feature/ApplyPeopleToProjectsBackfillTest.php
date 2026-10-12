<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\ApplyPeopleToProjectsBackfill;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Executor;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplyPeopleToProjectsBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backfill_sets_manager_for_tasks_without_one(): void
    {
        $manager = Executor::factory()->create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::EmailThread,
            'external_key' => 't1',
            'title' => 'Сроки',
            'status' => ProjectTaskStatus::Open,
            'manager_executor_id' => null,
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Applied,
            'subject' => 'Сроки',
            'body' => 'Ведёт Иван Иванов',
            'received_at' => now(),
        ]);

        $this->app->instance(AiAnalyzer::class, (new FakeAiAnalyzer)->withJsonPayload([
            'manager_executor_id' => $manager->id,
            'manager_name' => null,
            'assignee_executor_ids' => [],
            'assignee_names' => [],
            'confidence' => 0.9,
            'reason' => 'test',
        ]));

        $result = app(ApplyPeopleToProjectsBackfill::class)->handle(
            onlyMissingManager: true,
            limit: 10,
            dryRun: false,
        );

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['touched']);
        $this->assertSame($manager->id, $task->fresh()->manager_executor_id);
    }

    #[Test]
    public function dry_run_does_not_write(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
        ]);

        $task = ProjectTask::query()->create([
            'project_id' => $project->id,
            'source' => ProjectTaskSource::EmailThread,
            'external_key' => 't2',
            'status' => ProjectTaskStatus::Open,
            'manager_executor_id' => null,
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'classification' => MessageClassification::Client,
            'body' => 'x',
        ]);

        $result = app(ApplyPeopleToProjectsBackfill::class)->handle(
            onlyMissingManager: true,
            limit: 10,
            dryRun: true,
        );

        $this->assertSame(1, $result['touched']);
        $this->assertNull($task->fresh()->manager_executor_id);
    }

    #[Test]
    public function artisan_command_runs(): void
    {
        $this->artisan('projects:apply-people', ['--dry-run' => true])
            ->assertSuccessful();
    }
}
