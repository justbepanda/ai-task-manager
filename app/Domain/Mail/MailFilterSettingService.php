<?php

namespace App\Domain\Mail;

use App\Models\MailFilterSetting;
use App\Support\EmailAddress;

class MailFilterSettingService
{
    /**
     * @param  array{
     *     blocked_emails?: string|null,
     *     blocked_domains?: string|null,
     *     subject_stop_words?: string|null,
     *     body_stop_words?: string|null
     * }  $lines
     */
    public function saveFromLines(array $lines): MailFilterSetting
    {
        $settings = MailFilterSetting::current();

        $settings->fill([
            'blocked_emails' => $this->normalizeEmails($this->linesToList($lines['blocked_emails'] ?? null)),
            'blocked_domains' => $this->normalizeDomains($this->linesToList($lines['blocked_domains'] ?? null)),
            'subject_stop_words' => $this->linesToList($lines['subject_stop_words'] ?? null),
            'body_stop_words' => $this->linesToList($lines['body_stop_words'] ?? null),
        ]);
        $settings->save();

        return $settings->fresh();
    }

    public function addBlockedEmail(string $email): MailFilterSetting
    {
        $normalized = strtolower(trim($email));
        $settings = MailFilterSetting::current();
        $emails = EmailAddress::normalizeList($settings->blocked_emails);

        if (! in_array($normalized, $emails, true)) {
            $emails[] = $normalized;
            $settings->blocked_emails = $emails;
            $settings->save();
        }

        return $settings->fresh();
    }

    /**
     * @param  list<string>|null  $items
     */
    public function listToLines(?array $items): string
    {
        if ($items === null || $items === []) {
            return '';
        }

        return implode("\n", $items);
    }

    /**
     * @return list<string>
     */
    public function linesToList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/\R+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $parts,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function normalizeEmails(array $items): array
    {
        return array_values(array_unique(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            $items,
        )));
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function normalizeDomains(array $items): array
    {
        return array_values(array_unique(array_map(
            static function (string $item): string {
                $item = strtolower(trim($item));

                return ltrim($item, '@.');
            },
            $items,
        )));
    }
}
