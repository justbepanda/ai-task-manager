<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Domain\Classification\MailFilterMatcher;
use App\Enums\MessageClassification;
use App\Models\Message;

/**
 * Sync classification=ignored with current mail_filter_settings for already imported mail.
 */
class ApplyMailFiltersToMessages
{
    public function __construct(
        private readonly MailFilterMatcher $mailFilterMatcher,
        private readonly ClassifyAndMatchMessage $classifyAndMatch,
    ) {}

    /**
     * @return int Number of messages whose classification would change / did change
     */
    public function apply(bool $dryRun = false): int
    {
        $touched = 0;

        foreach (Message::query()->orderBy('id')->cursor() as $message) {
            $filterHit = $this->mailFilterMatcher->match($message) !== null;
            $isIgnored = $message->classification === MessageClassification::Ignored;

            if ($filterHit === $isIgnored) {
                continue;
            }

            $touched++;

            if ($dryRun) {
                continue;
            }

            $this->classifyAndMatch->handle($message);
        }

        return $touched;
    }
}
