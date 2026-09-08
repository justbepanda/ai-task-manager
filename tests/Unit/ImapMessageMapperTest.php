<?php

namespace Tests\Unit;

use App\Enums\MailSource;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\Imap\ImapMessageMapper;
use App\Mail\YandexMailProvider;
use Tests\TestCase;

class ImapMessageMapperTest extends TestCase
{
    public function test_prefers_message_id_header_as_external_id(): void
    {
        $mapper = new ImapMessageMapper;

        $headers = implode("\r\n", [
            'Message-ID: <unique-id@yandex.ru>',
            'From: Client Name <client@example.com>',
            'To: manager@yandex.ru, other@example.com',
            'Cc: cc@example.com',
            'Date: Mon, 7 Sep 2026 12:00:00 +0300',
            'Subject: =?UTF-8?B?0KLQtdGB0YI=?=',
            'In-Reply-To: <parent@example.com>',
            'References: <root@example.com> <parent@example.com>',
            '',
        ]);

        $message = $mapper->mapFromRaw(
            rawHeaders: $headers,
            rawBody: "Plain text body\r\nLine 2",
            source: MailSource::Yandex,
            folder: 'INBOX',
            uid: 55,
            uidvalidity: 99,
        );

        $this->assertSame('unique-id@yandex.ru', $message->externalId);
        $this->assertSame(MailSource::Yandex, $message->source);
        $this->assertSame('Client Name <client@example.com>', $message->sender);
        $this->assertSame(['manager@yandex.ru', 'other@example.com'], $message->recipients);
        $this->assertSame(['cc@example.com'], $message->cc);
        $this->assertSame("Plain text body\r\nLine 2", $message->body);
        $this->assertSame('parent@example.com', $message->headers['in-reply-to']);
        $this->assertSame(55, $message->metadata['imap_uid']);
        $this->assertSame('INBOX', $message->metadata['folder']);
        $this->assertSame(99, $message->metadata['uidvalidity']);
    }

    public function test_falls_back_to_folder_uidvalidity_uid_when_message_id_missing(): void
    {
        $mapper = new ImapMessageMapper;

        $headers = implode("\r\n", [
            'From: sender@example.com',
            'To: inbox@yandex.ru',
            'Date: Mon, 7 Sep 2026 12:00:00 +0300',
            'Subject: No Message-ID',
            '',
        ]);

        $message = $mapper->mapFromRaw($headers, 'Hello', MailSource::Yandex, 'INBOX', 12, 34);

        $this->assertSame('INBOX:34:12', $message->externalId);
    }

    public function test_strips_html_body_to_text(): void
    {
        $mapper = new ImapMessageMapper;

        $headers = "From: a@example.com\r\nTo: b@example.com\r\nSubject: HTML\r\n\r\n";
        $body = '<html><body><p>Hello &amp; welcome</p></body></html>';

        $message = $mapper->mapFromRaw($headers, $body);

        $this->assertSame('Hello & welcome', $message->body);
    }

    public function test_strips_style_and_script_from_html_emails(): void
    {
        $mapper = new ImapMessageMapper;

        $headers = "From: a@example.com\r\nTo: b@example.com\r\nSubject: Yandex\r\n\r\n";
        $body = <<<'HTML'
<html><head><style>.card a { color: #4D8EFF; } @media (prefers-color-scheme: dark) { .x{} }</style></head>
<body>
<p>Вы создали пароль для приложения в аккаунте user@example.com</p>
<p>Если это были не вы — смените пароль.</p>
</body></html>
HTML;

        $message = $mapper->mapFromRaw($headers, $body);

        $this->assertStringContainsString('Вы создали пароль для приложения', (string) $message->body);
        $this->assertStringNotContainsString('prefers-color-scheme', (string) $message->body);
        $this->assertStringNotContainsString('.card a', (string) $message->body);
        $this->assertStringNotContainsString('<style', (string) $message->body);
    }

    public function test_decodes_folded_mime_subject_without_truncation(): void
    {
        $mapper = new ImapMessageMapper;

        // "Вы создали пароль для приложения"
        $headers = "From: a@example.com\r\nTo: b@example.com\r\n"
            ."Subject: =?UTF-8?B?0JLRiyDRgdC+0LfQtNCw0LvQuCDQv9Cw0YDQvtC70Ywg?=\r\n"
            ." =?UTF-8?B?0LTQu9GPINC/0YDQuNC70L7QttC10L3QuNGP?=\r\n\r\n";

        $message = $mapper->mapFromRaw($headers, 'ok');

        $this->assertSame('Вы создали пароль для приложения', $message->subject);
    }

    public function test_unwraps_redmine_style_mimepart_base64_body(): void
    {
        $mapper = new ImapMessageMapper;

        $plain = "Задача #99247 обновлена.\n\nСтатус изменён на: Закрыта.\nАвтор: redmine@example.com\n\nОписание: критическая ошибка на странице заказа исправлена.";
        $b64 = chunk_split(base64_encode($plain), 76, "\n");

        $rawBody = "----==_mimepart_68be9f0a1a2b3_1234\n"
            ."Content-Type: text/plain; charset=UTF-8\n"
            ."Content-Transfer-Encoding: base64\n"
            ."\n"
            .$b64
            ."----==_mimepart_68be9f0a1a2b3_1234\n"
            ."Content-Type: text/html; charset=UTF-8\n"
            ."Content-Transfer-Encoding: base64\n"
            ."\n"
            .chunk_split(base64_encode('<html><body><p>HTML fallback</p></body></html>'), 76, "\n")
            ."----==_mimepart_68be9f0a1a2b3_1234--\n";

        $headers = "From: redmine@example.com\r\nTo: manager@yandex.ru\r\nSubject: [Redmine] #99247\r\n\r\n";
        $message = $mapper->mapFromRaw($headers, $rawBody);

        $this->assertTrue($mapper->looksLikeRawMime($rawBody));
        $this->assertStringContainsString('#99247', (string) $message->body);
        $this->assertStringContainsString('Задача', (string) $message->body);
        $this->assertStringContainsString('Закрыта', (string) $message->body);
        $this->assertStringNotContainsString('0JfQsNC00LDRh9Cw', (string) $message->body);
        $this->assertStringNotContainsString('Content-Transfer-Encoding', (string) $message->body);
        $this->assertStringNotContainsString('HTML fallback', (string) $message->body);
    }

    public function test_decodes_single_part_base64_entity_without_multipart(): void
    {
        $mapper = new ImapMessageMapper;

        $plain = 'Задача #99247: проверка single-part';
        $rawBody = "Content-Type: text/plain; charset=UTF-8\n"
            ."Content-Transfer-Encoding: base64\n"
            ."\n"
            .chunk_split(base64_encode($plain), 76, "\n");

        $message = $mapper->mapFromRaw(
            "From: a@example.com\r\nTo: b@example.com\r\nSubject: single\r\n\r\n",
            $rawBody,
        );

        $this->assertSame($plain, $message->body);
    }

    public function test_yandex_provider_requires_credentials_without_leaking_them(): void
    {
        config([
            'mail_providers.yandex.username' => '',
            'mail_providers.yandex.password' => '',
        ]);

        $provider = new YandexMailProvider;

        try {
            iterator_to_array($provider->fetchNewMessages());
            $this->fail('Expected MailProviderException');
        } catch (MailProviderException $e) {
            $this->assertStringContainsString('credentials are missing', $e->getMessage());
            $this->assertStringNotContainsString('password=', $e->getMessage());
        }
    }
}
