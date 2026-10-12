<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Classification\MessageClassifier;
use App\Domain\Classification\SiteLeadMatcher;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\MailFilterSetting;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteLeadMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function matches_callback_lead_from_rbi_domain(): void
    {
        $message = Message::factory()->create([
            'sender' => 'RBI <noreply@rbi.ru>',
            'subject' => 'Заявка на обратный звонок',
            'body' => 'Имя: Иван',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $this->assertTrue(app(SiteLeadMatcher::class)->matches($message));
    }

    #[Test]
    public function does_not_match_same_subject_from_unknown_domain(): void
    {
        $message = Message::factory()->create([
            'sender' => 'spam@example.com',
            'subject' => 'Заявка на обратный звонок',
            'body' => 'spam',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $this->assertFalse(app(SiteLeadMatcher::class)->matches($message));
    }

    #[Test]
    public function classifier_returns_site_lead_before_ignored_blacklist(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['noreply@'],
            'blocked_domains' => [],
            'subject_stop_words' => ['Заявка на обратный звонок'],
            'body_stop_words' => [],
        ]);

        $message = Message::factory()->create([
            'sender' => 'Site <noreply@rbi.ru>',
            'subject' => 'Заявка на обратный звонок',
            'body' => 'Телефон: +7…',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::SiteLead, $result->classification);
        $this->assertContains('site_lead_form', $result->reasons);
    }
}
