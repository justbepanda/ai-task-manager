<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keeps only the newest reply from nested email threads so Hermes/LLM
 * does not treat quoted history as the current client request.
 */
final class EmailLatestReplyExtractor
{
    /**
     * @return array{latest: string, quoted_excerpt: string|null}
     */
    public function split(string $rawBody, int $latestLimit = 4000, int $quotedLimit = 800): array
    {
        $plain = $this->toPlainText($rawBody);
        $plain = $this->normalizeWhitespace($plain);

        if ($plain === '') {
            return ['latest' => '', 'quoted_excerpt' => null];
        }

        $cutAt = $this->findQuoteStart($plain);
        $latest = $cutAt === null ? $plain : trim(mb_substr($plain, 0, $cutAt));
        $quoted = $cutAt === null ? null : trim(mb_substr($plain, $cutAt));

        // If the cut ate almost everything (false positive), keep full plain text.
        // Short real replies (slot confirmation, «ок») must still keep the cut when a long quote follows.
        if ($latest === '' || ($cutAt !== null && mb_strlen($latest) < 12 && mb_strlen($plain) > 400)) {
            $latest = $plain;
            $quoted = null;
        }

        $latest = $this->limit($latest, $latestLimit);
        $quotedExcerpt = $quoted !== null && $quoted !== ''
            ? $this->limit($quoted, $quotedLimit)
            : null;

        return [
            'latest' => $latest,
            'quoted_excerpt' => $quotedExcerpt,
        ];
    }

    public function latest(string $rawBody, int $limit = 4000): string
    {
        return $this->split($rawBody, $limit, 0)['latest'];
    }

    public function toPlainText(string $rawBody): string
    {
        $text = $rawBody;

        if (str_contains($text, '<') && str_contains($text, '>')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $text) ?? $text;
            $text = preg_replace('/<\s*\/\s*p\s*>/iu', "\n", $text) ?? $text;
            $text = preg_replace('/<\s*\/\s*div\s*>/iu', "\n", $text) ?? $text;
            $text = strip_tags($text);
        }

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function findQuoteStart(string $plain): ?int
    {
        $patterns = [
            // Outlook / Exchange (often flattened onto fewer lines after strip_tags).
            '/\nFrom:\s+.+?\sSent:\s/u',
            '/\nFrom:\s+.+?\sОтправлено:\s/ui',
            '/\nОт:\s+.+?\sОтправлено:\s/ui',
            '/\n-----Original Message-----/u',
            '/\n-----\s*Пересылаемое сообщение\s*-----/ui',
            '/\n________________________________\s*\n/u',
            '/\nOn .+? wrote:\s*\n/u',
            '/\n.+?\sнаписала?:\s*\n/ui',
            // Gmail / Apple Mail locale headers (RU), e.g. «пн, 3 авг. 2026 г. в 17:13, Name :»
            '/\n[а-яёa-z]{2},\s+\d{1,2}\s+[а-яё.]+\s+\d{4}\s+г\.\s+в\s+\d{1,2}:\d{2},\s+.+:/ui',
            '/\n[A-Za-z]{3},\s+\d{1,2}\s+[A-Za-z.]+\s+\d{4}\s+at\s+\d{1,2}:\d{2}.+:/u',
            // Local mailbox export style seen in Stavni threads.
            '/\n-{5,}\s*\nКому:\s/ui',
            '/\n-{5,}\s*Кому:\s/ui',
            // Classic quote header block.
            '/\nFrom:\s+[^\n]+\nSent:\s/ui',
            '/\nОт:\s+[^\n]+\nОтправлено:\s/ui',
        ];

        $earliest = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plain, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            /** @var array{0: string, 1: int} $match */
            $match = $matches[0];
            // preg_match offset is bytes; convert to mb offset via substr.
            $byteOffset = $match[1];
            $prefix = substr($plain, 0, $byteOffset);
            $mbOffset = mb_strlen($prefix);

            // Skip matches glued to the very start of the body.
            if ($mbOffset < 15) {
                continue;
            }

            if ($earliest === null || $mbOffset < $earliest) {
                $earliest = $mbOffset;
            }
        }

        return $earliest;
    }

    private function limit(string $text, int $limit): string
    {
        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)).'…';
    }
}
