<?php

namespace App\Domain\Classification;

use App\Models\MailFilterSetting;
use App\Models\Message;
use App\Support\EmailAddress;

/**
 * Global blacklist matcher (AGENTS: rules first, not LLM).
 * Returns a short match reason or null when the message should not be ignored.
 *
 * Subject/body stop words: plain substring (case-insensitive), or `regex:/.../flags`.
 */
class MailFilterMatcher
{
    private const BODY_SCAN_LIMIT = 8000;

    public function match(Message $message): ?string
    {
        return $this->matchParts(
            sender: $message->sender,
            subject: (string) ($message->subject ?? ''),
            body: (string) ($message->body ?? ''),
        );
    }

    public function matchParts(?string $sender, string $subject, string $body): ?string
    {
        $settings = MailFilterSetting::current();

        $senderEmail = EmailAddress::extract($sender);
        $senderDomain = EmailAddress::domain($sender);

        foreach ($this->normalizedList($settings->blocked_emails) as $pattern) {
            if ($senderEmail === null) {
                break;
            }
            if ($senderEmail === $pattern || str_contains($senderEmail, $pattern)) {
                return 'blocked_email:'.$pattern;
            }
        }

        foreach ($this->normalizedList($settings->blocked_domains) as $domain) {
            if ($senderDomain === null) {
                break;
            }
            if ($senderDomain === $domain || str_ends_with($senderDomain, '.'.$domain)) {
                return 'blocked_domain:'.$domain;
            }
        }

        foreach ($this->list($settings->subject_stop_words) as $word) {
            if ($this->textMatchesRule($subject, $word)) {
                return 'subject_stop_word:'.$word;
            }
        }

        $bodySample = mb_substr($body, 0, self::BODY_SCAN_LIMIT);
        foreach ($this->list($settings->body_stop_words) as $word) {
            if ($this->textMatchesRule($bodySample, $word)) {
                return 'body_stop_word:'.$word;
            }
        }

        return null;
    }

    private function textMatchesRule(string $haystack, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        if (str_starts_with($rule, 'regex:')) {
            $pattern = substr($rule, strlen('regex:'));
            if ($pattern === '') {
                return false;
            }

            $result = @preg_match($pattern, $haystack);

            return $result === 1;
        }

        return mb_stripos($haystack, $rule) !== false;
    }

    /**
     * @return list<string>
     */
    private function normalizedList(?array $items): array
    {
        return EmailAddress::normalizeList($items);
    }

    /**
     * @return list<string>
     */
    private function list(?array $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? trim($item) : '',
            $items,
        ), static fn (string $item): bool => $item !== ''));
    }
}
