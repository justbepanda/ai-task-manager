<?php

namespace Tests\Feature;

use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassificationAndMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_letter_matching_project_email_sets_client_and_project(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $project = $this->projectWithRules([
            'client_emails' => ['client@acme.com'],
            'domains' => ['acme.com'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'Client Name <client@acme.com>',
            'subject' => 'Question about delivery',
            'body' => 'When will it ship?',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Client, $message->classification);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        $this->assertProcessingSteps($message->id, ['classified', 'project_matched']);
        Queue::assertPushed(AnalyzeMessageJob::class, fn (AnalyzeMessageJob $job) => $job->messageId === $message->id);
    }

    public function test_technical_notification_classified_without_inventing_project(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $this->projectWithRules([
            'client_emails' => ['client@acme.com'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'GitHub <noreply@github.com>',
            'subject' => '[GitHub] New issue opened',
            'body' => 'You are receiving this because of notifications.',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Technical, $message->classification);
        $this->assertNull($message->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    public function test_newsletter_with_list_unsubscribe_header(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $message = $this->pendingMessage([
            'sender' => 'promo@marketing.example',
            'subject' => 'Weekly digest',
            'body' => 'Hello',
            'headers' => [
                'list-unsubscribe' => '<mailto:unsub@example.com>',
                'from' => 'promo@marketing.example',
            ],
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Newsletter, $message->classification);
        $this->assertNull($message->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    public function test_unknown_letter_needs_manual_and_null_project(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $this->projectWithRules([
            'client_emails' => ['known@acme.com'],
            'domains' => ['acme.com'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'stranger@random.org',
            'subject' => 'Hello there',
            'body' => 'Unrelated content',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Unknown, $message->classification);
        $this->assertNull($message->project_id);
        $this->assertSame(MessageProcessingStatus::NeedsManual, $message->processing_status);
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    public function test_exact_email_match_assigns_project(): void
    {
        $project = $this->projectWithRules([
            'client_emails' => ['exact@partner.ru'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'exact@partner.ru',
            'subject' => 'Hi',
            'body' => 'Body',
        ]);

        $match = app(\App\Domain\Projects\ProjectMatcher::class)->match($message);

        $this->assertSame($project->id, $match->projectId);
        $this->assertFalse($match->ambiguous);
    }

    public function test_domain_match_assigns_project(): void
    {
        $project = $this->projectWithRules([
            'domains' => ['partner.ru'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'anyone@partner.ru',
            'subject' => 'Update',
            'body' => 'Status',
        ]);

        $match = app(\App\Domain\Projects\ProjectMatcher::class)->match($message);

        $this->assertSame($project->id, $match->projectId);
        $this->assertFalse($match->ambiguous);
    }

    public function test_no_matching_project_leaves_null(): void
    {
        $this->projectWithRules([
            'client_emails' => ['only@one.com'],
            'domains' => ['one.com'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'nobody@elsewhere.com',
            'subject' => 'Ping',
            'body' => 'Nothing',
        ]);

        $match = app(\App\Domain\Projects\ProjectMatcher::class)->match($message);

        $this->assertNull($match->projectId);
        $this->assertFalse($match->ambiguous);
    }

    public function test_multiple_possible_projects_are_ambiguous_and_need_manual(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $this->projectWithRules([
            'client_emails' => ['shared@client.com'],
            'name' => 'Project A',
        ]);
        $this->projectWithRules([
            'client_emails' => ['shared@client.com'],
            'name' => 'Project B',
        ]);

        $message = $this->pendingMessage([
            'sender' => 'shared@client.com',
            'subject' => 'Which project?',
            'body' => 'Help',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Client, $message->classification);
        $this->assertNull($message->project_id);
        $this->assertSame(MessageProcessingStatus::NeedsManual, $message->processing_status);
        $this->assertTrue(data_get($message->metadata, 'project_match.ambiguous'));
        Queue::assertNotPushed(AnalyzeMessageJob::class);

        $log = ProcessingLog::query()
            ->where('message_id', $message->id)
            ->where('step', 'project_matched')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('ambiguous', $log->status);
        $this->assertGreaterThanOrEqual(2, count($log->details['candidates'] ?? []));
    }

    public function test_conversation_project_is_inherited(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Existing thread',
            'external_thread_id' => 'thread-1',
        ]);

        // No matching rules — inherit must come from conversation alone.
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'unknown@elsewhere.com',
            'subject' => 'Re: Existing thread',
            'body' => 'Follow-up',
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
            'project_id' => null,
            'headers' => [],
        ]);

        // Force client path so Analyze can fire: add a weak client rule that also matches? 
        // Spec: inherit project from conversation. Classification may still be unknown
        // without rules — but project should be set from conversation.
        $match = app(\App\Domain\Projects\ProjectMatcher::class)->match($message->fresh('conversation'));
        $this->assertSame($project->id, $match->projectId);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();
        $this->assertSame($project->id, $message->project_id);
    }

    public function test_technical_project_source_matches_project_without_ai(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $project = $this->projectWithRules([
            'technical_sources' => ['redmine.company.local'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'redmine@redmine.company.local',
            'subject' => '[Redmine] Bug #12 updated',
            'body' => 'Task updated',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Technical, $message->classification);
        $this->assertSame($project->id, $message->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    public function test_redmine_kelnik_subject_matches_project_by_name(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $tekta = $this->projectWithRules(['name' => 'Tekta']);
        $this->projectWithRules(['name' => 'RBI сайт 2025']);

        $message = $this->pendingMessage([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Tekta, Текта, ЛК клиента и брокера, поддержка - Bug #88] Ошибка в ЛК',
            'body' => 'Issue #88 was updated.',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Technical, $message->classification);
        $this->assertSame($tekta->id, $message->project_id);
        $this->assertContains(
            'redmine_subject:Tekta',
            $message->metadata['project_match']['reasons'] ?? [],
        );
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    public function test_redmine_kelnik_subject_matches_rbi_project_name(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $rbi = $this->projectWithRules(['name' => 'RBI сайт 2025']);

        $message = $this->pendingMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[RBI сайт 2025 - New #99143] (Обратная связь) Форма',
            'body' => 'New issue',
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(\App\Domain\Classification\ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame($rbi->id, $message->project_id);
    }

    public function test_classify_message_job_is_idempotent_when_already_classified(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        $project = $this->projectWithRules([
            'client_emails' => ['once@acme.com'],
        ]);

        $message = $this->pendingMessage([
            'sender' => 'once@acme.com',
            'subject' => 'Hi',
            'body' => 'Body',
        ]);

        $service = app(\App\Domain\Classification\ClassifyAndMatchMessage::class);
        (new ClassifyMessageJob($message->id))->handle($service);
        (new ClassifyMessageJob($message->id))->handle($service);

        $this->assertSame(1, ProcessingLog::query()->where('message_id', $message->id)->where('step', 'classified')->count());
        $this->assertSame($project->id, $message->fresh()->project_id);
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
    private function pendingMessage(array $attributes): Message
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
            'headers' => [
                'from' => $attributes['sender'] ?? 'sender@example.com',
            ],
        ], $attributes));
    }

    /**
     * @param  list<string>  $steps
     */
    private function assertProcessingSteps(int $messageId, array $steps): void
    {
        foreach ($steps as $step) {
            $this->assertDatabaseHas('processing_logs', [
                'message_id' => $messageId,
                'step' => $step,
            ]);
        }
    }
}
