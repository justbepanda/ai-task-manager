<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EmailLatestReplyExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailLatestReplyExtractorTest extends TestCase
{
    #[Test]
    public function extracts_latest_reply_before_outlook_from_sent_block(): void
    {
        $body = <<<'TEXT'
Ксения, добрый день!
По оверлею вижу, спасибо.
По отключению метрик – считаю, так делать не стоит, тк метрики должны быть на сайте.
Перенести ниже по странице – что это значит?

С уважением,
ЯНА ШУКЕЛЬ
Руководитель отдела маркетинга и коммуникаций
Девелоперская компания STAVNI
+7 (952) 229 22 40
From: Ксения Егорова Sent: Thursday, July 23, 2026 7:02 PM To: Яна Шукель Subject: Re: доработки по сайту
Яна, добрый день. Оверлей готов. Бюджет согласован, когда начинаем перенос на новую ВМ?
TEXT;

        $split = (new EmailLatestReplyExtractor)->split($body);

        $this->assertStringContainsString('По оверлею вижу', $split['latest']);
        $this->assertStringContainsString('Перенести ниже по странице', $split['latest']);
        $this->assertStringNotContainsString('когда начинаем перенос', $split['latest']);
        $this->assertNotNull($split['quoted_excerpt']);
        $this->assertStringContainsString('From: Ксения Егорова', (string) $split['quoted_excerpt']);
    }

    #[Test]
    public function extracts_before_original_message_marker(): void
    {
        $body = "Клиент задаёт новый вопрос по срокам поставки.\n\n-----Original Message-----\nOld thread about VM migration";

        $latest = (new EmailLatestReplyExtractor)->latest($body);

        $this->assertSame('Клиент задаёт новый вопрос по срокам поставки.', $latest);
    }

    #[Test]
    public function strips_html_before_split(): void
    {
        $body = '<p>Ксения, добрый день!</p><p>По оверлею вижу, спасибо.</p>'
            .'<br>From: Manager Sent: Yesterday<br>Старый вопрос про ВМ';

        $latest = (new EmailLatestReplyExtractor)->latest($body);

        $this->assertStringContainsString('По оверлею вижу', $latest);
        $this->assertStringNotContainsString('Старый вопрос про ВМ', $latest);
    }

    #[Test]
    public function extracts_gmail_russian_quote_header(): void
    {
        $body = "Удобнее в среду в 16:00.\nспасибо\n\n"
            ."пн, 3 авг. 2026 г. в 17:13, Константин П. <pk@kelnik.ru>:\n"
            ."Светлана, добрый день! Когда удобно созвониться по демо?";

        $split = (new EmailLatestReplyExtractor)->split($body);

        $this->assertStringContainsString('Удобнее в среду в 16:00', $split['latest']);
        $this->assertStringNotContainsString('Когда удобно созвониться', $split['latest']);
        $this->assertNotNull($split['quoted_excerpt']);
    }

    #[Test]
    public function keeps_full_body_when_no_quote_markers(): void
    {
        $body = 'Короткое письмо без цитат.';

        $split = (new EmailLatestReplyExtractor)->split($body);

        $this->assertSame($body, $split['latest']);
        $this->assertNull($split['quoted_excerpt']);
    }
}
