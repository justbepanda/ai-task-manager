<?php

namespace App\Mail\Imap;

/**
 * IMAP modified UTF-7 (RFC 3501) ↔ UTF-8.
 *
 * Prefer mbstring UTF7-IMAP over imap_utf7_decode(): the latter does not
 * reliably produce UTF-8 for Cyrillic mailbox names, which breaks exclude matching.
 */
final class ImapUtf7
{
    public static function decode(string $mailbox): string
    {
        if ($mailbox === '' || ! str_contains($mailbox, '&')) {
            return $mailbox;
        }

        if (! function_exists('mb_convert_encoding')) {
            return $mailbox;
        }

        $decoded = @mb_convert_encoding($mailbox, 'UTF-8', 'UTF7-IMAP');

        if (! is_string($decoded) || $decoded === '') {
            return $mailbox;
        }

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            return $mailbox;
        }

        // Reject binary leftovers from a failed decode (NUL / C0 controls).
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $decoded) === 1) {
            return $mailbox;
        }

        return $decoded;
    }
}
