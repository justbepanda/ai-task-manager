<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\MailFilterSetting;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyMailFiltersReprocessTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_mail_filters_marks_matching_existing_messages_ignored(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['noreply@'],
            'blocked_domains' => ['id.yandex.com'],
            'subject_stop_words' => [
                \Database\Seeders\MailFilterSettingSeeder::REDMINE_PODDERZHKA_OCENKA_REGEX,
            ],
            'body_stop_words' => [],
        ]);

        $byEmail = Message::factory()->create([
            'sender' => 'Yandex <noreply@id.yandex.ru>',
            'subject' => 'Password',
            'body' => 'x',
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
        ]);

        $bySubject = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Поддержка - Bug #98322] (Обратная связь) Оценка: Юрий Устинов',
            'body' => 'updated',
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $wronglyIgnored = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта, ЛК клиента и брокера, поддержка - Support #99435] Оценка: ЛК Брокера | Карточка клиента (2)',
            'body' => 'updated',
            'classification' => MessageClassification::Ignored,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $keep = Message::factory()->create([
            'sender' => 'client@acme.com',
            'subject' => 'Срок поставки',
            'body' => 'Когда будет?',
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $this->artisan('messages:reprocess', ['--apply-mail-filters' => true])
            ->assertSuccessful();

        $this->assertSame(MessageClassification::Ignored, $byEmail->fresh()->classification);
        $this->assertSame(MessageClassification::Ignored, $bySubject->fresh()->classification);
        $this->assertNotSame(MessageClassification::Ignored, $wronglyIgnored->fresh()->classification);
        $this->assertSame(MessageClassification::Client, $keep->fresh()->classification);
    }

    public function test_apply_mail_filters_dry_run_does_not_write(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => [
                \Database\Seeders\MailFilterSettingSeeder::REDMINE_PODDERZHKA_OCENKA_REGEX,
            ],
            'body_stop_words' => [],
        ]);

        $message = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Поддержка - Bug #1] Оценка: Иван Иванов',
            'body' => 'x',
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        $this->artisan('messages:reprocess', [
            '--apply-mail-filters' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(MessageClassification::Technical, $message->fresh()->classification);
    }
}
