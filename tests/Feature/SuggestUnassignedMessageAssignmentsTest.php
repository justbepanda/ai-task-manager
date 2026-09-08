<?php

namespace Tests\Feature;

use App\Domain\Projects\SuggestUnassignedMessageAssignments;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestUnassignedMessageAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_redmine_kelnik_mail_forms_new_project_group_not_catch_all(): void
    {
        $this->projectWithRules([
            'name' => 'Redmine / техподдержка',
            'subject_patterns' => ['[Redmine]'],
            'keywords' => ['issue', 'ticket'],
            'technical_sources' => ['redmine'],
        ]);

        $tektaMail = $this->unassignedMessage([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Tekta, Текта, ЛК клиента и брокера, поддержка - Bug #88] Ошибка',
            'body' => 'Issue #88 was updated.',
        ]);

        $rbiMail = $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[RBI сайт 2025 - New #99143] (Обратная связь) Форма',
            'body' => 'New issue created.',
        ]);

        $board = app(SuggestUnassignedMessageAssignments::class)->build();

        $this->assertCount(2, $board->groups);
        $this->assertTrue($board->groups[0]->isNew || $board->groups[1]->isNew);

        $titles = array_map(static fn ($g) => $g->title, $board->groups);
        $this->assertContains('Tekta (новый)', $titles);
        $this->assertContains('RBI сайт 2025 (новый)', $titles);

        foreach ($board->groups as $group) {
            $this->assertTrue($group->isNew);
            $this->assertNull($group->project);
        }

        $tektaGroup = collect($board->groups)->firstWhere('suggestedName', 'Tekta');
        $this->assertNotNull($tektaGroup);
        $this->assertSame([$tektaMail->id], array_map(static fn ($s) => $s->message->id, $tektaGroup->suggestions));

        $rbiGroup = collect($board->groups)->firstWhere('suggestedName', 'RBI сайт 2025');
        $this->assertNotNull($rbiGroup);
        $this->assertSame([$rbiMail->id], array_map(static fn ($s) => $s->message->id, $rbiGroup->suggestions));
    }

    public function test_redmine_mail_attaches_to_existing_named_project(): void
    {
        $tekta = $this->projectWithRules(['name' => 'Tekta']);

        $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта - Bug #1] Ping',
            'body' => 'Updated',
        ]);

        $board = app(SuggestUnassignedMessageAssignments::class)->build();

        $this->assertCount(1, $board->groups);
        $this->assertFalse($board->groups[0]->isNew);
        $this->assertSame($tekta->id, $board->groups[0]->project?->id);
        $this->assertSame('Tekta', $board->groups[0]->title);
    }

    public function test_matcher_does_not_auto_assign_redmine_kelnik_to_catch_all(): void
    {
        $catchAll = $this->projectWithRules([
            'name' => 'Redmine / техподдержка',
            'keywords' => ['issue', 'ticket'],
            'technical_sources' => ['redmine'],
        ]);

        $message = $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта - Bug #9] X',
            'body' => 'Issue updated',
        ]);

        $match = app(\App\Domain\Projects\ProjectMatcher::class)->match($message);

        $this->assertNull($match->projectId);
        $this->assertFalse(collect($match->candidates)->contains(
            static fn ($c) => $c->projectId === $catchAll->id,
        ));
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function unassignedMessage(array $attributes): Message
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
            'headers' => [],
        ], $attributes));
    }
}
