<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\ApplyPeopleToProjectsBackfill;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Executor;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplyPeopleToProjectsBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backfill_sets_manager_for_projects_without_one(): void
    {
        $manager = Executor::factory()->create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'manager_executor_id' => null,
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
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
        $this->assertSame($manager->id, $project->fresh()->manager_executor_id);
    }

    #[Test]
    public function dry_run_does_not_write(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'manager_executor_id' => null,
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'body' => 'x',
        ]);

        $result = app(ApplyPeopleToProjectsBackfill::class)->handle(
            onlyMissingManager: true,
            limit: 10,
            dryRun: true,
        );

        $this->assertSame(1, $result['touched']);
        $this->assertNull($project->fresh()->manager_executor_id);
    }

    #[Test]
    public function artisan_command_runs(): void
    {
        $this->artisan('projects:apply-people', ['--dry-run' => true])
            ->assertSuccessful();
    }
}
