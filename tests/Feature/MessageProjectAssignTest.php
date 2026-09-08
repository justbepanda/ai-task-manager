<?php

namespace Tests\Feature;

use App\Domain\Projects\BulkAttachMessagesToProject;
use App\Domain\Projects\SuggestUnassignedMessageAssignments;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessageProjectAssignTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function suggest_groups_messages_by_matcher_candidate(): void
    {
        $project = $this->projectWithRules([
            'name' => 'Acme Shop',
            'client_emails' => ['client@acme.com'],
            'domains' => ['acme.com'],
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'sender' => 'old@acme.com',
            'subject' => 'Already attached',
            'received_at' => now()->subDay(),
        ]);

        $matched = Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'sender' => 'Client <client@acme.com>',
            'subject' => 'Need help',
            'body' => 'Question',
            'received_at' => now(),
        ]);

        $unmatched = Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'sender' => 'stranger@random.org',
            'subject' => 'Hello',
            'body' => 'No match',
            'received_at' => now()->subHour(),
        ]);

        Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Ignored,
            'sender' => 'noreply@yandex.ru',
            'subject' => 'Ignored',
            'received_at' => now(),
        ]);

        $board = app(SuggestUnassignedMessageAssignments::class)->build();

        $this->assertCount(1, $board->groups);
        $this->assertSame($project->id, $board->groups[0]->project->id);
        $this->assertSame(1, $board->groups[0]->existingCount);
        $this->assertCount(1, $board->groups[0]->suggestions);
        $this->assertSame($matched->id, $board->groups[0]->suggestions[0]->message->id);
        $this->assertFalse($board->groups[0]->suggestions[0]->ambiguous);

        $unmatchedIds = array_map(static fn (Message $m) => $m->id, $board->unmatched);
        $this->assertContains($unmatched->id, $unmatchedIds);
        $this->assertNotContains($matched->id, $unmatchedIds);
    }

    #[Test]
    public function suggest_marks_ambiguous_when_two_projects_close(): void
    {
        config([
            'classification.project_match_auto_threshold' => 0.8,
            'classification.project_match_suggest_threshold' => 0.55,
            'classification.project_match_ambiguous_margin' => 0.1,
        ]);

        $this->projectWithRules([
            'name' => 'Project A',
            'client_emails' => ['shared@example.com'],
        ]);
        $this->projectWithRules([
            'name' => 'Project B',
            'client_emails' => ['shared@example.com'],
        ]);

        $message = Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Client,
            'sender' => 'shared@example.com',
            'subject' => 'Ambiguous',
            'body' => 'Body',
            'received_at' => now(),
        ]);

        $board = app(SuggestUnassignedMessageAssignments::class)->build();

        $this->assertCount(1, $board->groups);
        $this->assertCount(1, $board->groups[0]->suggestions);
        $this->assertSame($message->id, $board->groups[0]->suggestions[0]->message->id);
        $this->assertTrue($board->groups[0]->suggestions[0]->ambiguous);
    }

    #[Test]
    public function bulk_attach_sets_project_writes_correction_and_dispatches_analyze(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'sender' => 'client@acme.com',
            'subject' => 'Help',
        ]);
        $already = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $count = app(BulkAttachMessagesToProject::class)->attach($project->id, [
            $message->id,
            $already->id,
        ]);

        $this->assertSame(1, $count);
        $this->assertSame($project->id, $message->fresh()->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->fresh()->processing_status);
        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $message->id,
            'field' => 'project_id',
            'manager_value' => (string) $project->id,
        ]);
        Queue::assertPushed(AnalyzeMessageJob::class, fn (AnalyzeMessageJob $job) => $job->messageId === $message->id);
    }

    #[Test]
    public function create_project_and_attach_creates_active_project(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $message = Message::factory()->create([
            'project_id' => null,
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'sender' => 'x@y.com',
            'subject' => 'New',
        ]);

        $result = app(BulkAttachMessagesToProject::class)->createProjectAndAttach(
            'Новый проект из почты',
            [$message->id],
        );

        $this->assertSame('Новый проект из почты', $result['project']->name);
        $this->assertSame(ProjectStatus::Active, $result['project']->status);
        $this->assertSame(1, $result['attached']);
        $this->assertSame($result['project']->id, $message->fresh()->project_id);
        $this->assertDatabaseHas('projects', ['name' => 'Новый проект из почты']);
    }

    #[Test]
    public function orchid_assign_screen_loads_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.messages.assign')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Распределение по проектам');
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function projectWithRules(array $rules): Project
    {
        $name = $rules['name'] ?? null;
        unset($rules['name']);

        $project = Project::factory()->create([
            'name' => $name ?? fake()->unique()->company().' Project',
            'status' => ProjectStatus::Active,
        ]);

        ProjectMatchingRule::query()->create(array_merge([
            'project_id' => $project->id,
            'client_emails' => null,
            'staff_emails' => null,
            'domains' => null,
            'subject_patterns' => null,
            'keywords' => null,
            'technical_sources' => null,
            'custom_rules' => null,
        ], $rules));

        return $project->fresh('matchingRule');
    }
}
