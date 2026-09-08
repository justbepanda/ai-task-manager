<?php

namespace Tests\Feature;

use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\Conversation;
use App\Models\MailFilterSetting;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailFilterIgnoredPipelineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ignored_message_is_classified_without_analyze_job(): void
    {
        Queue::fake([AnalyzeMessageJob::class]);

        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => ['Код подтверждения'],
            'body_stop_words' => [],
        ]);

        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'Код подтверждения',
            'external_thread_id' => 'thread-ignored-1',
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'id@yandex.ru',
            'subject' => 'Код подтверждения',
            'body' => '123456',
            'project_id' => null,
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
        ]);

        (new ClassifyMessageJob($message->id))->handle(app(ClassifyAndMatchMessage::class));

        $message->refresh();

        $this->assertSame(MessageClassification::Ignored, $message->classification);
        $this->assertNull($message->project_id);
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        Queue::assertNotPushed(AnalyzeMessageJob::class);
    }

    #[Test]
    public function analyze_message_job_skips_ignored(): void
    {
        Bus::fake();

        $message = Message::factory()->create([
            'classification' => MessageClassification::Ignored,
            'processing_status' => MessageProcessingStatus::Classified,
            'project_id' => null,
            'subject' => 'Security',
            'body' => 'Ignore me',
        ]);

        (new AnalyzeMessageJob($message->id))->handle(app(\App\AI\Services\AnalyzeMessage::class));

        $message->refresh();
        $this->assertSame(MessageProcessingStatus::Classified, $message->processing_status);
        $this->assertNull($message->ai_summary);
        Bus::assertNothingDispatched();
    }
}
