<?php

namespace Tests\Unit;

use App\Domain\Classification\MailFilterMatcher;
use App\Domain\Classification\MessageClassifier;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\MailFilterSetting;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailFilterMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function blocked_email_classifies_as_ignored(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['noreply@'],
            'blocked_domains' => [],
            'subject_stop_words' => [],
            'body_stop_words' => [],
        ]);

        $message = Message::factory()->create([
            'sender' => 'Yandex <noreply@id.yandex.ru>',
            'subject' => 'Hello',
            'body' => 'Body',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Ignored, $result->classification);
        $this->assertStringContainsString('blocked_email:', $result->reasons[0] ?? '');
    }

    #[Test]
    public function blocked_domain_classifies_as_ignored(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => ['id.yandex.ru'],
            'subject_stop_words' => [],
            'body_stop_words' => [],
        ]);

        $message = Message::factory()->create([
            'sender' => 'alert@id.yandex.ru',
            'subject' => 'Login',
            'body' => 'Body',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Ignored, $result->classification);
        $this->assertSame('blocked_domain:id.yandex.ru', $result->reasons[0] ?? null);
    }

    #[Test]
    public function subject_stop_word_classifies_as_ignored(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => ['пароль для приложения'],
            'body_stop_words' => [],
        ]);

        $message = Message::factory()->create([
            'sender' => 'security@example.com',
            'subject' => 'Ваш пароль для приложения создан',
            'body' => 'Details',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(MessageClassifier::class)->classify($message);

        $this->assertSame(MessageClassification::Ignored, $result->classification);
        $this->assertStringContainsString('subject_stop_word:', $result->reasons[0] ?? '');
    }

    #[Test]
    public function subject_stop_word_ocenka_matches_podderzhka_person_name(): void
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

        $ignored = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Поддержка - Bug #98322] (Обратная связь) Оценка: Юрий Устинов',
            'body' => 'Issue updated',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $keep = Message::factory()->create([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта, ЛК клиента и брокера, поддержка - Support #99435] Оценка: ЛК Брокера | Карточка клиента (2)',
            'body' => 'Issue updated',
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $ignoredResult = app(MessageClassifier::class)->classify($ignored);
        $keepResult = app(MessageClassifier::class)->classify($keep);

        $this->assertSame(MessageClassification::Ignored, $ignoredResult->classification);
        $this->assertNotSame(MessageClassification::Ignored, $keepResult->classification);
    }

    #[Test]
    public function subject_stop_word_ignores_lead_forms_but_not_redmine_feedback(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => [
                'Заявка на обратный звонок',
                'Заказ обратного звонка',
                'Заявка на бронирование',
                'Заявка на рассрочку',
                'Заявка на обратную связь',
            ],
            'body_stop_words' => [],
        ]);

        $matcher = app(MailFilterMatcher::class);

        $this->assertNotNull($matcher->matchParts(
            sender: 'noreply@rbi.ru',
            subject: 'Заявка на обратный звонок',
            body: '',
        ));
        $this->assertNotNull($matcher->matchParts(
            sender: 'noreply@rbi.ru',
            subject: 'Заказ обратного звонка',
            body: '',
        ));
        $this->assertNotNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Новая заявка на обратную связь',
            body: '',
        ));
        $this->assertNull($matcher->matchParts(
            sender: 'redmine@kelnik.pro',
            subject: '[RBI, РБИ - Support #99139] (Обратная связь) Пропуск выбора этажа',
            body: '',
        ));
    }

    #[Test]
    public function matcher_returns_null_when_no_rule_hits(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => ['noreply@'],
            'blocked_domains' => ['id.yandex.ru'],
            'subject_stop_words' => ['Код подтверждения'],
            'body_stop_words' => ['Яндекс ID'],
        ]);

        $message = Message::factory()->create([
            'sender' => 'client@acme.com',
            'subject' => 'Question',
            'body' => 'When is the delivery?',
        ]);

        $this->assertNull(app(MailFilterMatcher::class)->match($message));
    }

    #[Test]
    public function lk_qa_noise_is_ignored_but_fault_reports_are_not(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => [
                'Новая заявка на брокер-тур',
                'Ваш список недвижимости',
                \Database\Seeders\MailFilterSettingSeeder::LK_BOOKING_SUBJECT_REGEX,
            ],
            'body_stop_words' => [
                'согласен с условиями оплаты и отмены бронирования',
                'Вы можете ознакомиться со списком в приложении',
            ],
        ]);

        $matcher = app(MailFilterMatcher::class);

        $this->assertNotNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Плашиннов Иван, 2-комнатная евро № 308',
            body: 'Клиент согласен с условиями оплаты и отмены бронирования',
        ));
        $this->assertNotNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Иванова, Студия № 222',
            body: '',
        ));
        $this->assertNotNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Новая заявка на брокер-тур',
            body: '',
        ));
        $this->assertNotNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Ваш список недвижимости',
            body: 'Вы можете ознакомиться со списком в приложении',
        ));
        $this->assertNull($matcher->matchParts(
            sender: 'LK@tekta.com',
            subject: 'Сообщение о неисправности',
            body: 'Не открывается карточка клиента на стенде lk-dev.tekta.ru',
        ));
    }

    #[Test]
    public function automatic_reply_subject_is_ignored(): void
    {
        MailFilterSetting::query()->create([
            'id' => 1,
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => [
                'Автоматический ответ:',
                'Автоматический ответ',
            ],
            'body_stop_words' => [],
        ]);

        $matcher = app(MailFilterMatcher::class);

        $this->assertNotNull($matcher->matchParts(
            sender: 'ed@certit.ru',
            subject: 'Автоматический ответ: Запрос по реализации узбекской версии планировок в Profitbase',
            body: 'Я в отпуске',
        ));
    }
}
