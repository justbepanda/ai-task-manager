<?php

namespace App\Enums;

enum ManagerActionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Approved => 'Подтверждено',
            self::Edited => 'Отредактировано',
            self::Rejected => 'Отклонено',
            self::Completed => 'Выполнено',
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
