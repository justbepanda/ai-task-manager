<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AiAnalyzer;
use App\AI\Exceptions\AnalysisException;
use App\AI\FakeAiAnalyzer;
use App\Domain\Projects\AssignmentBoardPlan;
use App\Domain\Projects\SuggestUnassignedMessageAssignmentsWithLlm;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestUnassignedMessageAssignmentsWithLlmTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_groups_from_valid_llm_payload(): void
    {
        $project = Project::factory()->create([
            'name' => 'Tekta',
            'status' => ProjectStatus::Active,
        ]);

        $matched = $this->unassignedMessage([
            'sender' => 'client@example.com',
            'subject' => 'Срок по Tekta',
            'body' => 'Когда будет готово?',
        ]);
        $newGroupMail = $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[RBI сайт 2025 - Bug #1] X',
            'body' => 'Updated',
        ]);
        $unmatched = $this->unassignedMessage([
            'sender' => 'spam@news.example',
            'subject' => 'Рассылка',
            'body' => 'Buy now',
        ]);

        $fake = (new FakeAiAnalyzer)->withJsonPayload([
            'groups' => [
                [
                    'project_id' => $project->id,
                    'message_ids' => [$matched->id],
                    'confidence' => 0.92,
                    'reason' => 'Тема про Tekta',
                ],
                [
                    'project_id' => null,
                    'proposed_name' => 'RBI сайт 2025',
                    'proposed_aliases' => ['RBI'],
                    'message_ids' => [$newGroupMail->id],
                    'confidence' => 0.88,
                    'reason' => 'Redmine тема',
                ],
            ],
            'unmatched_message_ids' => [$unmatched->id],
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $board = app(SuggestUnassignedMessageAssignmentsWithLlm::class)->build();

        $this->assertCount(2, $board->groups);
        $this->assertCount(1, $board->unmatched);
        $this->assertSame($unmatched->id, $board->unmatched[0]->id);

        $existing = collect($board->groups)->first(fn ($g) => ! $g->isNew);
        $this->assertNotNull($existing);
        $this->assertSame($project->id, $existing->project?->id);
        $this->assertSame([$matched->id], array_map(static fn ($s) => $s->message->id, $existing->suggestions));

        $proposed = collect($board->groups)->first(fn ($g) => $g->isNew);
        $this->assertNotNull($proposed);
        $this->assertSame('RBI сайт 2025', $proposed->suggestedName);
        $this->assertSame([$newGroupMail->id], array_map(static fn ($s) => $s->message->id, $proposed->suggestions));
    }

    public function test_ignores_unknown_project_and_duplicate_message_ids(): void
    {
        $project = Project::factory()->create([
            'name' => 'Alpha',
            'status' => ProjectStatus::Active,
        ]);

        $mail = $this->unassignedMessage([
            'sender' => 'a@b.c',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        $fake = (new FakeAiAnalyzer)->withJsonPayload([
            'groups' => [
                [
                    'project_id' => 999999,
                    'message_ids' => [$mail->id],
                    'confidence' => 0.9,
                    'reason' => 'fake',
                ],
                [
                    'project_id' => $project->id,
                    'message_ids' => [$mail->id, $mail->id],
                    'confidence' => 0.8,
                    'reason' => 'ok',
                ],
            ],
            'unmatched_message_ids' => [],
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $board = app(SuggestUnassignedMessageAssignmentsWithLlm::class)->build();

        $this->assertCount(1, $board->groups);
        $this->assertFalse($board->groups[0]->isNew);
        $this->assertCount(1, $board->groups[0]->suggestions);
        $this->assertSame($mail->id, $board->groups[0]->suggestions[0]->message->id);
        $this->assertSame([], $board->unmatched);
    }

    public function test_messages_not_listed_by_llm_fall_to_unmatched(): void
    {
        $project = Project::factory()->create([
            'name' => 'Beta',
            'status' => ProjectStatus::Active,
        ]);

        $listed = $this->unassignedMessage(['subject' => 'A', 'body' => 'a']);
        $forgotten = $this->unassignedMessage(['subject' => 'B', 'body' => 'b']);

        $fake = (new FakeAiAnalyzer)->withJsonPayload([
            'groups' => [
                [
                    'project_id' => $project->id,
                    'message_ids' => [$listed->id],
                    'confidence' => 0.9,
                    'reason' => 'listed',
                ],
            ],
            'unmatched_message_ids' => [],
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $board = app(SuggestUnassignedMessageAssignmentsWithLlm::class)->build();

        $this->assertCount(1, $board->unmatched);
        $this->assertSame($forgotten->id, $board->unmatched[0]->id);
    }

    public function test_invalid_payload_without_groups_throws(): void
    {
        $this->unassignedMessage(['subject' => 'X', 'body' => 'y']);

        $fake = (new FakeAiAnalyzer)->withJsonPayload([
            'unmatched_message_ids' => [],
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $this->expectException(AnalysisException::class);

        app(SuggestUnassignedMessageAssignmentsWithLlm::class)->build();
    }

    public function test_assignment_board_plan_roundtrip(): void
    {
        $project = Project::factory()->create([
            'name' => 'Gamma',
            'status' => ProjectStatus::Active,
        ]);
        $mail = $this->unassignedMessage(['subject' => 'S', 'body' => 'b']);

        $fake = (new FakeAiAnalyzer)->withJsonPayload([
            'groups' => [
                [
                    'project_id' => $project->id,
                    'message_ids' => [$mail->id],
                    'confidence' => 0.91,
                    'reason' => 'match',
                ],
            ],
            'unmatched_message_ids' => [],
        ]);
        $this->app->instance(AiAnalyzer::class, $fake);

        $board = app(SuggestUnassignedMessageAssignmentsWithLlm::class)->build();
        $hydrated = AssignmentBoardPlan::hydrate(AssignmentBoardPlan::serialize($board));

        $this->assertCount(1, $hydrated->groups);
        $this->assertSame($project->id, $hydrated->groups[0]->project?->id);
        $this->assertSame($mail->id, $hydrated->groups[0]->suggestions[0]->message->id);
    }

    public function test_without_message_ids_keeps_other_groups(): void
    {
        $plan = [
            'groups' => [
                [
                    'project_id' => 1,
                    'title' => 'A',
                    'is_new' => false,
                    'existing_count' => 2,
                    'suggested_name' => null,
                    'suggested_aliases' => [],
                    'suggestions' => [
                        ['message_id' => 10, 'score' => 0.9, 'reasons' => ['a'], 'ambiguous' => false],
                        ['message_id' => 11, 'score' => 0.8, 'reasons' => ['b'], 'ambiguous' => false],
                    ],
                ],
                [
                    'project_id' => 2,
                    'title' => 'B',
                    'is_new' => false,
                    'existing_count' => 0,
                    'suggested_name' => null,
                    'suggested_aliases' => [],
                    'suggestions' => [
                        ['message_id' => 20, 'score' => 0.7, 'reasons' => ['c'], 'ambiguous' => false],
                    ],
                ],
            ],
            'unmatched_ids' => [30],
        ];

        $updated = AssignmentBoardPlan::withoutMessageIds($plan, [10, 11], attachedToProjectId: 1, attachedCount: 2);

        $this->assertCount(1, $updated['groups']);
        $this->assertSame(2, $updated['groups'][0]['project_id']);
        $this->assertSame([20], array_column($updated['groups'][0]['suggestions'], 'message_id'));
        $this->assertSame([30], $updated['unmatched_ids']);
        $this->assertFalse(AssignmentBoardPlan::isEmpty($updated));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function unassignedMessage(array $attributes = []): Message
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
            'sender' => 'sender@example.com',
            'subject' => 'Subject',
            'body' => 'Body',
            'received_at' => now(),
        ], $attributes));
    }
}
