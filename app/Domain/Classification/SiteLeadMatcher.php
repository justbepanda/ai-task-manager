<?php

declare(strict_types=1);

namespace App\Domain\Classification;

use App\Models\Message;
use App\Support\EmailAddress;

/**
 * Deterministic matcher for website lead forms (callback / booking / etc.).
 * Must run before MailFilterMatcher: noreply@ on lead domains is often blacklisted.
 */
final class SiteLeadMatcher
{
    /**
     * Default subject fragments when config omits site_lead_subjects.
     *
     * @var list<string>
     */
    private const DEFAULT_SUBJECTS = [
        'Заявка на обратный звонок',
        'Заказ обратного звонка',
        'Заявка на бронирование',
        'Заявка на рассрочку',
        'Заявка на обратную связь',
        'Новая заявка на обратную связь',
        'Заявка с сайта',
        'Новая заявка',
        'Заявка на консультацию',
        'Заявка на подбор',
    ];

    public function matches(Message $message): bool
    {
        return $this->matchesParts(
            sender: $message->sender,
            subject: (string) ($message->subject ?? ''),
        );
    }

    public function matchesParts(?string $sender, string $subject): bool
    {
        if (! $this->subjectLooksLikeLead($subject)) {
            return false;
        }

        return $this->senderDomainIsLeadSource($sender);
    }

    private function subjectLooksLikeLead(string $subject): bool
    {
        $subject = trim($subject);
        if ($subject === '') {
            return false;
        }

        foreach ($this->subjects() as $needle) {
            if ($needle !== '' && mb_stripos($subject, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function senderDomainIsLeadSource(?string $sender): bool
    {
        $domain = EmailAddress::domain($sender);
        if ($domain === null || $domain === '') {
            return false;
        }

        foreach ($this->domains() as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if ($domain === $allowed || str_ends_with($domain, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function domains(): array
    {
        return EmailAddress::normalizeList(config('classification.site_lead_domains', []));
    }

    /**
     * @return list<string>
     */
    private function subjects(): array
    {
        $configured = config('classification.site_lead_subjects');
        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_SUBJECTS;
        }

        $out = [];
        foreach ($configured as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out !== [] ? $out : self::DEFAULT_SUBJECTS;
    }
}
