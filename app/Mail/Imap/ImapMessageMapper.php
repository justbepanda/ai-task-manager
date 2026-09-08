<?php

namespace App\Mail\Imap;

use App\Enums\MailSource;
use App\Mail\DTO\NormalizedIncomingMessage;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ImapMessageMapper
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function map(
        string $rawHeaders,
        string $rawBody,
        MailSource $source,
        string $folder,
        int $uid,
        int $uidvalidity,
        ?object $structure = null,
        ?ImapMailbox $mailbox = null,
    ): NormalizedIncomingMessage {
        $parsed = $this->parseHeaders($rawHeaders);

        $messageId = $this->normalizeMessageId($parsed['message-id'] ?? null);
        $externalId = $messageId !== ''
            ? $messageId
            : sprintf('%s:%d:%d', $folder, $uidvalidity, $uid);

        $body = $this->extractBody($rawBody, $structure, $mailbox, $uid);

        return new NormalizedIncomingMessage(
            source: $source,
            externalId: $externalId,
            sender: $parsed['from'] ?? '',
            recipients: $this->splitAddresses($parsed['to'] ?? null),
            cc: $this->splitAddresses($parsed['cc'] ?? null),
            subject: $parsed['subject'] ?? null,
            body: $body,
            receivedAt: $this->parseDate($parsed['date'] ?? null),
            headers: [
                'message-id' => $messageId !== '' ? $messageId : ($parsed['message-id'] ?? null),
                'in-reply-to' => $this->normalizeMessageId($parsed['in-reply-to'] ?? null) ?: null,
                'references' => $parsed['references'] ?? null,
                'from' => $parsed['from'] ?? null,
                'to' => $parsed['to'] ?? null,
                'cc' => $parsed['cc'] ?? null,
                'date' => $parsed['date'] ?? null,
            ],
            metadata: [
                'imap_uid' => $uid,
                'folder' => $folder,
                'uidvalidity' => $uidvalidity,
            ],
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
        );
    }

    /**
     * Map from fixture header/body strings without a live IMAP connection.
     */
    public function mapFromRaw(
        string $rawHeaders,
        string $rawBody,
        MailSource $source = MailSource::Yandex,
        string $folder = 'INBOX',
        int $uid = 1,
        int $uidvalidity = 1,
    ): NormalizedIncomingMessage {
        return $this->map(
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
            source: $source,
            folder: $folder,
            uid: $uid,
            uidvalidity: $uidvalidity,
        );
    }

    /**
     * @return array<string, string>
     */
    public function parseHeaders(string $rawHeaders): array
    {
        // Normalize newlines first, then unfold RFC 5322 continuation lines (leading WSP).
        $normalized = str_replace(["\r\n", "\r"], "\n", $rawHeaders);
        $normalized = preg_replace("/\n[ \t]+/", ' ', $normalized) ?? $normalized;
        $lines = preg_split("/\n/", $normalized) ?: [];
        $headers = [];

        foreach ($lines as $line) {
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $headers[$key] = trim($value);
        }

        if (isset($headers['subject'])) {
            $headers['subject'] = $this->decodeMimeHeader($headers['subject']);
        }

        foreach (['from', 'to', 'cc'] as $addressHeader) {
            if (isset($headers[$addressHeader])) {
                $headers[$addressHeader] = $this->decodeMimeHeader($headers[$addressHeader]);
            }
        }

        return $headers;
    }

    public function normalizeMessageId(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $value = trim($value);

        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value, " \t<>");
    }

    /**
     * @return list<string>
     */
    public function splitAddresses(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style|head|noscript|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<!--.*?-->#s', ' ', $text) ?? $text;
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*/\s*(p|div|tr|li|h[1-6]|table|section|article|header|footer)\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*(p|div|tr|li|h[1-6]|section|article)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function looksLikeHtml(string $content): bool
    {
        $sample = strtolower(substr(ltrim($content), 0, 2000));

        if ($sample === '') {
            return false;
        }

        return str_contains($sample, '<html')
            || str_contains($sample, '<!doctype')
            || str_contains($sample, '<body')
            || str_contains($sample, '<table')
            || str_contains($sample, '<div')
            || str_contains($sample, '<style')
            || str_contains($sample, 'mj-column')
            || (str_contains($sample, '<') && str_contains($sample, '</'));
    }

    public function looksLikeRawMime(string $content): bool
    {
        $sample = ltrim($content);

        if ($sample === '') {
            return false;
        }

        $head = substr($sample, 0, 4000);

        if (preg_match('/^----==_mimepart_/m', $head) === 1) {
            return true;
        }

        if (
            preg_match('/^--[A-Za-z0-9\'()+_,.\\/=?-]+(?:--)?\s*$/m', $head) === 1
            && (
                stripos($head, 'Content-Type:') !== false
                || stripos($head, 'Content-Transfer-Encoding:') !== false
            )
        ) {
            return true;
        }

        return preg_match(
            '/^Content-(?:Type|Transfer-Encoding|Disposition):/im',
            $head,
        ) === 1;
    }

    public function looksLikePureBase64(string $content): bool
    {
        $trimmed = trim($content);

        if (strlen($trimmed) < 64) {
            return false;
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? '';

        if (strlen($compact) < 64 || preg_match('/^[A-Za-z0-9+\/]+=*$/', $compact) !== 1) {
            return false;
        }

        $lines = preg_split("/\R/", $trimmed) ?: [];
        $longBase64Lines = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && strlen($line) >= 60 && preg_match('/^[A-Za-z0-9+\/]+=*$/', $line) === 1) {
                $longBase64Lines++;
            }
        }

        return $longBase64Lines >= 2 || strlen($compact) >= 200;
    }

    public function extractTextFromRawMime(string $raw): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $normalized = trim($normalized);

        if ($normalized === '') {
            return '';
        }

        $parts = $this->splitMimeParts($normalized);

        if ($parts === []) {
            $decoded = $this->decodeSingleMimeEntity($normalized);

            return $decoded !== '' ? $decoded : $normalized;
        }

        $plain = null;
        $html = null;

        foreach ($parts as $part) {
            $parsed = $this->parseMimeEntity($part);
            $contentType = strtolower($parsed['content_type']);

            if (str_starts_with($contentType, 'multipart/')) {
                continue;
            }

            $decoded = $this->decodeTransferEncoded(
                $parsed['body'],
                $parsed['encoding'],
                $parsed['charset'],
            );

            if ($decoded === '') {
                continue;
            }

            if (str_contains($contentType, 'text/plain') && $plain === null) {
                $plain = $decoded;
            } elseif (str_contains($contentType, 'text/html') && $html === null) {
                $html = $this->htmlToText($decoded);
            } elseif ($plain === null && $html === null && str_starts_with($contentType, 'text/')) {
                $plain = $this->looksLikeHtml($decoded) ? $this->htmlToText($decoded) : $decoded;
            }
        }

        if ($plain !== null && trim($plain) !== '') {
            return trim($plain);
        }

        if ($html !== null && trim($html) !== '') {
            return trim($html);
        }

        return $normalized;
    }

    private function extractBody(
        string $rawBody,
        ?object $structure,
        ?ImapMailbox $mailbox,
        int $uid,
    ): ?string {
        $candidate = null;

        if ($structure !== null && $mailbox !== null) {
            $plain = $this->findPartBody($structure, $mailbox, $uid, 'TEXT/PLAIN');
            if ($plain !== null && trim($plain) !== '') {
                $candidate = trim($plain);
            }

            if ($candidate === null || $this->looksLikeHtml($candidate)) {
                $html = $this->findPartBody($structure, $mailbox, $uid, 'TEXT/HTML');
                if ($html !== null && trim($html) !== '') {
                    $candidate = $this->htmlToText($html);
                } elseif ($candidate !== null && $this->looksLikeHtml($candidate)) {
                    $candidate = $this->htmlToText($candidate);
                }
            }
        }

        if ($candidate === null) {
            $trimmed = trim($rawBody);

            if ($trimmed === '') {
                return null;
            }

            $candidate = $this->looksLikeHtml($trimmed)
                ? $this->htmlToText($trimmed)
                : $trimmed;
        } elseif ($this->looksLikeHtml($candidate)) {
            $candidate = $this->htmlToText($candidate);
        }

        $candidate = $this->normalizeExtractedBody($candidate);

        return $candidate !== '' ? $candidate : null;
    }

    private function normalizeExtractedBody(string $candidate): string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return '';
        }

        if ($this->looksLikeRawMime($candidate)) {
            $extracted = $this->extractTextFromRawMime($candidate);
            if (trim($extracted) !== '') {
                $candidate = trim($extracted);
            }
        }

        if ($this->looksLikePureBase64($candidate)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $candidate) ?? '', true);
            if (is_string($decoded) && $decoded !== '' && $this->looksLikeReadableText($decoded)) {
                $candidate = $this->looksLikeHtml($decoded)
                    ? $this->htmlToText($decoded)
                    : trim($decoded);
            }
        }

        return $candidate;
    }

    /**
     * @return list<string>
     */
    private function splitMimeParts(string $raw): array
    {
        if (preg_match('/^(----==_mimepart_[^\n]+)/m', $raw, $match) === 1) {
            $boundary = trim($match[1]);
        } elseif (preg_match('/^--([A-Za-z0-9\'()+_,.\\/=?-]+)\s*$/m', $raw, $match) === 1) {
            $boundary = '--'.trim($match[1]);
        } else {
            return [];
        }

        $escaped = preg_quote($boundary, '/');
        $chunks = preg_split('/(?:^|\n)'.$escaped.'(?:--)?\s*(?:\n|$)/', $raw) ?: [];
        $parts = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }
            $parts[] = $chunk;
        }

        return $parts;
    }

    /**
     * @return array{content_type: string, encoding: string, charset: string, body: string}
     */
    private function parseMimeEntity(string $entity): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $entity);
        $normalized = preg_replace("/\n[ \t]+/", ' ', $normalized) ?? $normalized;

        $headerBlock = $normalized;
        $body = '';

        if (preg_match("/\n\n(.*)\z/s", $normalized, $match) === 1) {
            $splitAt = strpos($normalized, "\n\n");
            $headerBlock = $splitAt === false ? $normalized : substr($normalized, 0, $splitAt);
            $body = $match[1];
        }

        $headers = [];
        foreach (preg_split("/\n/", $headerBlock) ?: [] as $line) {
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $contentType = $headers['content-type'] ?? 'text/plain';
        $charset = 'UTF-8';
        if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $charsetMatch) === 1) {
            $charset = trim($charsetMatch[1], "\"'");
        }

        return [
            'content_type' => $contentType,
            'encoding' => strtolower($headers['content-transfer-encoding'] ?? '7bit'),
            'charset' => $charset,
            'body' => $body,
        ];
    }

    private function decodeSingleMimeEntity(string $raw): string
    {
        $parsed = $this->parseMimeEntity($raw);

        if (
            $parsed['body'] === ''
            && stripos($raw, 'Content-Transfer-Encoding:') === false
            && stripos($raw, 'Content-Type:') === false
        ) {
            return '';
        }

        $decoded = $this->decodeTransferEncoded(
            $parsed['body'] !== '' ? $parsed['body'] : $raw,
            $parsed['encoding'],
            $parsed['charset'],
        );

        if ($decoded === '') {
            return '';
        }

        $contentType = strtolower($parsed['content_type']);

        if (str_contains($contentType, 'text/html') || $this->looksLikeHtml($decoded)) {
            return $this->htmlToText($decoded);
        }

        return trim($decoded);
    }

    private function decodeTransferEncoded(string $body, string $encoding, string $charset): string
    {
        $encoding = strtolower(trim($encoding));
        $decoded = match ($encoding) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? '', true) ?: $body,
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        if (! is_string($decoded)) {
            $decoded = $body;
        }

        $charset = strtoupper(trim($charset));
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8' && $charset !== 'US-ASCII') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        return $decoded;
    }

    private function looksLikeReadableText(string $content): bool
    {
        if ($content === '' || ! mb_check_encoding($content, 'UTF-8')) {
            return false;
        }

        $sample = substr($content, 0, 500);
        $len = strlen($sample);
        if ($len === 0) {
            return false;
        }

        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample) ?: 0;

        return ($control / $len) < 0.1;
    }

    private function findPartBody(object $structure, ImapMailbox $mailbox, int $uid, string $mime): ?string
    {
        $partNumber = $this->findPartNumber($structure, strtoupper($mime));

        if ($partNumber === null) {
            return null;
        }

        $body = $mailbox->fetchBody($uid, $partNumber === '0' ? '' : $partNumber);
        $part = $this->findPartByNumber($structure, $partNumber);

        return $this->decodePart($body, $part);
    }

    private function findPartNumber(object $structure, string $mime, string $prefix = ''): ?string
    {
        $type = $this->mimeType($structure);

        if ($type === $mime && empty($structure->parts)) {
            return $prefix === '' ? '1' : $prefix;
        }

        if (empty($structure->parts)) {
            return $type === $mime ? ($prefix === '' ? '1' : $prefix) : null;
        }

        foreach ($structure->parts as $index => $part) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $found = $this->findPartNumber($part, $mime, $number);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function findPartByNumber(object $structure, string $partNumber): ?object
    {
        if ($partNumber === '1' && empty($structure->parts)) {
            return $structure;
        }

        $segments = array_map('intval', explode('.', $partNumber));
        $current = $structure;

        foreach ($segments as $segment) {
            if (empty($current->parts[$segment - 1])) {
                return null;
            }
            $current = $current->parts[$segment - 1];
        }

        return $current;
    }

    private function mimeType(object $part): string
    {
        $primary = [
            0 => 'TEXT',
            1 => 'MULTIPART',
            2 => 'MESSAGE',
            3 => 'APPLICATION',
            4 => 'AUDIO',
            5 => 'IMAGE',
            6 => 'VIDEO',
            7 => 'OTHER',
        ];

        $type = $primary[$part->type ?? 0] ?? 'OTHER';
        $subtype = strtoupper((string) ($part->subtype ?? 'PLAIN'));

        return $type.'/'.$subtype;
    }

    private function decodePart(string $body, ?object $part): string
    {
        $encoding = (int) ($part->encoding ?? 0);

        $decoded = match ($encoding) {
            3 => base64_decode($body, true) ?: $body,
            4 => quoted_printable_decode($body),
            default => $body,
        };

        $charset = null;
        if (! empty($part->parameters)) {
            foreach ($part->parameters as $parameter) {
                if (strtolower((string) ($parameter->attribute ?? '')) === 'charset') {
                    $charset = (string) $parameter->value;
                    break;
                }
            }
        }

        if ($charset !== null && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        return $decoded;
    }

    private function decodeMimeHeader(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Join adjacent RFC 2047 encoded-words (optionally separated by whitespace).
        $value = preg_replace('/\?=\s+=\?/', '?==?', $value) ?? $value;

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return $this->normalizeDecodedHeader($decoded);
            }
        }

        if (function_exists('imap_mime_header_decode')) {
            $parts = @imap_mime_header_decode($value);
            if (is_array($parts) && $parts !== []) {
                $decoded = '';
                foreach ($parts as $part) {
                    $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
                    $text = (string) $part->text;
                    if ($charset !== 'UTF-8' && $charset !== 'DEFAULT' && $charset !== 'US-ASCII') {
                        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                        $text = $converted !== false ? $converted : $text;
                    }
                    $decoded .= $text;
                }

                return $this->normalizeDecodedHeader($decoded);
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            return $this->normalizeDecodedHeader(mb_decode_mimeheader($value));
        }

        return $this->normalizeDecodedHeader($value);
    }

    private function normalizeDecodedHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);
    }

    private function parseDate(?string $date): CarbonInterface
    {
        if ($date === null || trim($date) === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return Carbon::now();
        }
    }
}
