<?php

namespace App\Enums;

enum MessageClassification: string
{
    case Client = 'client';
    case Technical = 'technical';
    case Newsletter = 'newsletter';
    case Personal = 'personal';
    case Ignored = 'ignored';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Клиентское',
            self::Technical => 'Техническое',
            self::Newsletter => 'Рассылка',
            self::Personal => 'Личное',
            self::Ignored => 'Игнорируемое',
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
