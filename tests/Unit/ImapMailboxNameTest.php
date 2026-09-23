<?php

namespace Tests\Unit;

use App\Mail\Imap\ImapMailboxName;
use App\Mail\Imap\ImapUtf7;
use Tests\TestCase;

class ImapMailboxNameTest extends TestCase
{
    public function test_quote_wraps_names_with_comma_or_space(): void
    {
        $folder = '&BBMEOAQzBDAEPwQ7BDAEPQ-, &BD8EOARBBEwEPAQw-';

        $this->assertSame('"'.$folder.'"', ImapMailboxName::quoteForOpen($folder));
        $this->assertSame('INBOX', ImapMailboxName::quoteForOpen('INBOX'));
        $this->assertSame('Archive', ImapMailboxName::quoteForOpen('Archive'));
        $this->assertSame('"Folder with spaces"', ImapMailboxName::quoteForOpen('Folder with spaces'));
        $this->assertSame('"Already quoted"', ImapMailboxName::quoteForOpen('"Already quoted"'));
    }

    public function test_utf7_decode_roundtrips_cyrillic_folder_names(): void
    {
        if (! function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('mbstring required');
        }

        foreach (['Отправленные', 'Черновики', 'Гигаплан, Письма'] as $name) {
            $encoded = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
            $this->assertIsString($encoded);
            $this->assertSame($name, ImapUtf7::decode($encoded));
        }
    }

    public function test_utf7_decode_real_yandex_comma_folder(): void
    {
        if (! function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('mbstring required');
        }

        $encoded = '&BBMEOAQzBDAEPwQ7BDAEPQ-, &BD8EOARBBEwEPAQw-';
        $decoded = ImapUtf7::decode($encoded);

        $this->assertNotSame($encoded, $decoded);
        $this->assertStringContainsString(',', $decoded);
        $this->assertTrue(mb_check_encoding($decoded, 'UTF-8'));
    }
}
