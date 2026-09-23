<?php

namespace App\Enums;

enum ProjectTaskSource: string
{
    case Redmine = 'redmine';
    case Jira = 'jira';
    case EmailThread = 'email_thread';

    public function label(): string
    {
        return match ($this) {
            self::Redmine => 'Redmine',
            self::Jira => 'Jira',
            self::EmailThread => 'Переписка',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
