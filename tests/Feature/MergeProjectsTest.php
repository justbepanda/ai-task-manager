<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Projects\MergeProjects;
use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeProjectsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function merges_cyrillic_duplicate_into_latin_canonical(): void
    {
        $target = Project::factory()->create([
            'name' => 'GigaPlan',
            'status' => ProjectStatus::Active,
        ]);
        ProjectMatchingRule::query()->create([
            'project_id' => $target->id,
            'keywords' => ['GIG'],
            'client_emails' => [],
            'staff_emails' => [],
            'domains' => [],
            'subject_patterns' => [],
            'technical_sources' => [],
            'custom_rules' => [],
        ]);

        $source = Project::factory()->create([
            'name' => 'Гигаплан',
            'status' => ProjectStatus::Active,
        ]);
        ProjectMatchingRule::query()->create([
            'project_id' => $source->id,
            'keywords' => ['Гигаплан'],
            'client_emails' => [],
            'staff_emails' => [],
            'domains' => [],
            'subject_patterns' => [],
            'technical_sources' => ['redmine'],
            'custom_rules' => [],
        ]);

        $kept = Message::factory()->create([
            'project_id' => $target->id,
            'classification' => MessageClassification::Technical,
        ]);
        $moved = Message::factory()->create([
            'project_id' => $source->id,
            'classification' => MessageClassification::Technical,
        ]);

        $result = app(MergeProjects::class)->handle($target, $source);

        $this->assertSame($target->id, $result['target']->id);
        $this->assertSame(1, $result['messages_moved']);
        $this->assertSame($target->id, $moved->fresh()->project_id);
        $this->assertSame($target->id, $kept->fresh()->project_id);
        $this->assertNull(Project::query()->find($source->id));

        $keywords = $target->fresh('matchingRule')->matchingRule?->keywords ?? [];
        $this->assertContains('Гигаплан', $keywords);
        $this->assertContains('GigaPlan', $keywords);
        $this->assertContains('GIG', $keywords);
    }

    #[Test]
    public function find_by_folded_names_resolves_cyrillic_to_latin(): void
    {
        $project = Project::factory()->create([
            'name' => 'GigaPlan',
            'status' => ProjectStatus::Active,
        ]);

        $found = app(MergeProjects::class)->findByFoldedNames(['Гигаплан']);

        $this->assertNotNull($found);
        $this->assertSame($project->id, $found->id);
    }

    #[Test]
    public function artisan_merge_duplicates_dry_run(): void
    {
        Project::factory()->create(['name' => 'GigaPlan', 'status' => ProjectStatus::Active]);
        Project::factory()->create(['name' => 'Гигаплан', 'status' => ProjectStatus::Active]);

        $this->artisan('projects:merge-duplicates', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(2, Project::query()->count());
    }
}
