<?php

namespace App\Enums;

enum ProjectTaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Feedback = 'feedback';
    case Done = 'done';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыта',
            self::InProgress => 'В работе',
            self::Feedback => 'Обратная связь',
            self::Done => 'Закрыта',
            self::Unknown => 'Неизвестно',
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
