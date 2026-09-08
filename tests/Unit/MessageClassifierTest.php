<?php

namespace Tests\Unit;

use App\Domain\Classification\MessageClassifier;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_precedence_bulk_is_newsletter(): void
    {
        $message = Message::factory()->create([
            'sender' => 'list@example.com',
            'subject' => 'Digest',
            'body' => 'Hi',
            'headers' => ['precedence' => 'bulk'],
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Newsletter, $result->classification);
    }

    public function test_staff_email_treated_as_client_classification(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        ProjectMatchingRule::query()->create([
            'project_id' => $project->id,
            'staff_emails' => ['dev@company.com'],
        ]);

        $message = Message::factory()->create([
            'sender' => 'dev@company.com',
            'subject' => 'Internal note',
            'body' => 'FYI',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Client, $result->classification);
    }

    public function test_personal_skipped_when_allowlist_empty(): void
    {
        config(['classification.personal_emails' => [], 'classification.personal_domains' => []]);

        $message = Message::factory()->create([
            'sender' => 'friend@gmail.com',
            'subject' => 'Weekend',
            'body' => 'See you',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Unknown, $result->classification);
    }
}
