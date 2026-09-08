<?php

namespace App\Enums;

enum MessageProcessingStatus: string
{
    case Pending = 'pending';
    case Classified = 'classified';
    case Analyzed = 'analyzed';
    case Applied = 'applied';
    case Failed = 'failed';
    case NeedsManual = 'needs_manual';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Classified => 'Классифицировано',
            self::Analyzed => 'Проанализировано',
            self::Applied => 'Применено',
            self::Failed => 'Ошибка',
            self::NeedsManual => 'Требует ручной обработки',
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
