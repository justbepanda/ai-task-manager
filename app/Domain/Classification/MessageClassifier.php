<?php

namespace App\Domain\Classification;

use App\Domain\Classification\DTO\ClassificationResult;
use App\Enums\MessageClassification;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Support\EmailAddress;

/**
 * Deterministic classification — first match wins (AGENTS §6, §22):
 * 0. ignored (global MailFilterMatcher blacklist)
 * 1. technical  2. newsletter  3. personal  4. client  5. unknown
 *
 * `ignored` is for system/Yandex-ID/security mail (admin blacklist).
 * `technical` remains for project-related sources (Redmine/Jira/etc.).
 */
class MessageClassifier
{
    public function __construct(
        private readonly MailFilterMatcher $mailFilterMatcher,
    ) {}

    public function classify(Message $message): ClassificationResult
    {
        if ($result = $this->matchIgnored($message)) {
            return $result;
        }

        if ($result = $this->matchTechnical($message)) {
            return $result;
        }

        if ($result = $this->matchNewsletter($message)) {
            return $result;
        }

        if ($result = $this->matchPersonal($message)) {
            return $result;
        }

        if ($result = $this->matchClient($message)) {
            return $result;
        }

        return new ClassificationResult(
            classification: MessageClassification::Unknown,
            confidence: (float) config('classification.confidence.unknown', 0.2),
            reasons: ['no_deterministic_rule_matched'],
        );
    }

    private function matchIgnored(Message $message): ?ClassificationResult
    {
        $reason = $this->mailFilterMatcher->match($message);
        if ($reason === null) {
            return null;
        }

        return new ClassificationResult(
            MessageClassification::Ignored,
            (float) config('classification.confidence.ignored', 0.99),
            [$reason],
        );
    }

    private function matchTechnical(Message $message): ?ClassificationResult
    {
        $senderEmail = EmailAddress::extract($message->sender);
        $senderDomain = EmailAddress::domain($message->sender);
        $subject = (string) ($message->subject ?? '');
        $body = (string) ($message->body ?? '');
        $haystack = $subject.' '.$body;

        foreach (config('classification.technical_senders', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if ($senderEmail !== null && ($senderEmail === $pattern || str_contains($senderEmail, $pattern))) {
                return new ClassificationResult(
                    MessageClassification::Technical,
                    (float) config('classification.confidence.technical_sender', 0.95),
                    ['technical_sender:'.$pattern],
                );
            }

            if ($senderDomain !== null && ($senderDomain === $pattern || str_ends_with($senderDomain, '.'.$pattern) || str_contains($senderDomain, $pattern))) {
                return new ClassificationResult(
                    MessageClassification::Technical,
                    (float) config('classification.confidence.technical_sender', 0.95),
                    ['technical_sender_domain:'.$pattern],
                );
            }
        }

        foreach ($this->activeMatchingRules() as $rule) {
            foreach (EmailAddress::normalizeList($rule->technical_sources) as $source) {
                if ($source === '') {
                    continue;
                }
                if ($this->sourceMatchesMessage($source, $senderEmail, $senderDomain, $subject, $haystack, $message)) {
                    return new ClassificationResult(
                        MessageClassification::Technical,
                        (float) config('classification.confidence.technical_project_source', 0.9),
                        ['project_technical_source:'.$source, 'project_id:'.$rule->project_id],
                    );
                }
            }
        }

        foreach (config('classification.technical_subject_patterns', []) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && stripos($subject, $pattern) !== false) {
                return new ClassificationResult(
                    MessageClassification::Technical,
                    (float) config('classification.confidence.technical_pattern', 0.85),
                    ['technical_subject_pattern:'.$pattern],
                );
            }
        }

        foreach (config('classification.technical_body_patterns', []) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && stripos($body, $pattern) !== false) {
                return new ClassificationResult(
                    MessageClassification::Technical,
                    (float) config('classification.confidence.technical_pattern', 0.85),
                    ['technical_body_pattern:'.$pattern],
                );
            }
        }

        return null;
    }

    private function matchNewsletter(Message $message): ?ClassificationResult
    {
        $headers = $this->normalizedHeaders($message);

        if (config('classification.newsletter_header_hints', true)) {
            if ($this->headerPresent($headers, 'list-unsubscribe')) {
                return new ClassificationResult(
                    MessageClassification::Newsletter,
                    (float) config('classification.confidence.newsletter_header', 0.95),
                    ['header:list-unsubscribe'],
                );
            }

            $precedence = strtolower((string) ($this->headerValue($headers, 'precedence') ?? ''));
            if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
                return new ClassificationResult(
                    MessageClassification::Newsletter,
                    (float) config('classification.confidence.newsletter_header', 0.95),
                    ['header:precedence:'.$precedence],
                );
            }
        }

        $senderEmail = EmailAddress::extract($message->sender) ?? '';
        foreach (config('classification.newsletter_senders', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && str_contains($senderEmail, $pattern)) {
                return new ClassificationResult(
                    MessageClassification::Newsletter,
                    (float) config('classification.confidence.newsletter_pattern', 0.8),
                    ['newsletter_sender:'.$pattern],
                );
            }
        }

        $body = (string) ($message->body ?? '');
        foreach (config('classification.newsletter_body_patterns', []) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && stripos($body, $pattern) !== false) {
                return new ClassificationResult(
                    MessageClassification::Newsletter,
                    (float) config('classification.confidence.newsletter_pattern', 0.8),
                    ['newsletter_body_pattern:'.$pattern],
                );
            }
        }

        return null;
    }

    private function matchPersonal(Message $message): ?ClassificationResult
    {
        $emails = EmailAddress::normalizeList(config('classification.personal_emails', []));
        $domains = EmailAddress::normalizeList(config('classification.personal_domains', []));

        if ($emails === [] && $domains === []) {
            return null;
        }

        $senderEmail = EmailAddress::extract($message->sender);
        $senderDomain = EmailAddress::domain($message->sender);

        if ($senderEmail !== null && in_array($senderEmail, $emails, true)) {
            return new ClassificationResult(
                MessageClassification::Personal,
                (float) config('classification.confidence.personal', 0.85),
                ['personal_email:'.$senderEmail],
            );
        }

        if ($senderDomain !== null && in_array($senderDomain, $domains, true)) {
            return new ClassificationResult(
                MessageClassification::Personal,
                (float) config('classification.confidence.personal', 0.85),
                ['personal_domain:'.$senderDomain],
            );
        }

        return null;
    }

    private function matchClient(Message $message): ?ClassificationResult
    {
        $senderDomain = EmailAddress::domain($message->sender);
        $subject = (string) ($message->subject ?? '');
        $body = (string) ($message->body ?? '');
        $participantEmails = $this->participantEmails($message);

        foreach ($this->activeMatchingRules() as $rule) {
            $clientEmails = EmailAddress::normalizeList($rule->client_emails);
            $staffEmails = EmailAddress::normalizeList($rule->staff_emails);
            $domains = EmailAddress::normalizeList($rule->domains);

            foreach ($participantEmails as $email) {
                if (in_array($email, $clientEmails, true) || in_array($email, $staffEmails, true)) {
                    return new ClassificationResult(
                        MessageClassification::Client,
                        (float) config('classification.confidence.client_email', 0.92),
                        ['project_email_match:'.$email, 'project_id:'.$rule->project_id],
                    );
                }
            }

            if ($senderDomain !== null && in_array($senderDomain, $domains, true)) {
                return new ClassificationResult(
                    MessageClassification::Client,
                    (float) config('classification.confidence.client_domain', 0.85),
                    ['project_domain_match:'.$senderDomain, 'project_id:'.$rule->project_id],
                );
            }

            foreach ($rule->subject_patterns ?? [] as $pattern) {
                if ($this->patternMatches((string) $pattern, $subject)) {
                    return new ClassificationResult(
                        MessageClassification::Client,
                        (float) config('classification.confidence.client_subject', 0.78),
                        ['project_subject_pattern:'.$pattern, 'project_id:'.$rule->project_id],
                    );
                }
            }

            foreach ($rule->keywords ?? [] as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword === '') {
                    continue;
                }
                if (stripos($subject, $keyword) !== false || stripos($body, $keyword) !== false) {
                    return new ClassificationResult(
                        MessageClassification::Client,
                        (float) config('classification.confidence.client_keyword', 0.7),
                        ['project_keyword:'.$keyword, 'project_id:'.$rule->project_id],
                    );
                }
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\ProjectMatchingRule>
     */
    private function activeMatchingRules()
    {
        return Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->get()
            ->map(fn (Project $project) => $project->matchingRule)
            ->filter();
    }

    /**
     * @return list<string>
     */
    private function participantEmails(Message $message): array
    {
        return array_values(array_unique(array_merge(
            EmailAddress::extractMany($message->sender),
            EmailAddress::extractMany($message->recipients),
            EmailAddress::extractMany($message->cc),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedHeaders(Message $message): array
    {
        $headers = $message->headers ?? [];
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = $value;
        }

        return $normalized;
    }

    private function headerPresent(array $headers, string $name): bool
    {
        $value = $this->headerValue($headers, $name);

        return $value !== null && trim((string) $value) !== '';
    }

    private function headerValue(array $headers, string $name): mixed
    {
        return $headers[strtolower($name)] ?? null;
    }

    private function sourceMatchesMessage(
        string $source,
        ?string $senderEmail,
        ?string $senderDomain,
        string $subject,
        string $haystack,
        Message $message,
    ): bool {
        if ($senderEmail !== null && ($senderEmail === $source || str_contains($senderEmail, $source))) {
            return true;
        }

        if ($senderDomain !== null && str_contains($senderDomain, $source)) {
            return true;
        }

        if (stripos($subject, $source) !== false || stripos($haystack, $source) !== false) {
            return true;
        }

        foreach ($this->normalizedHeaders($message) as $value) {
            if (is_string($value) && stripos($value, $source) !== false) {
                return true;
            }
        }

        return false;
    }

    private function patternMatches(string $pattern, string $subject): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (strlen($pattern) >= 2 && $pattern[0] === '/' && str_ends_with($pattern, '/')) {
            return @preg_match($pattern, $subject) === 1;
        }

        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/iu';

            return @preg_match($regex, $subject) === 1;
        }

        return stripos($subject, $pattern) !== false;
    }
}
