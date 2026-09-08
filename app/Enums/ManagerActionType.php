<?php

namespace App\Enums;

enum ManagerActionType: string
{
    case NoAction = 'no_action';
    case Review = 'review';
    case Reply = 'reply';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'Без действия',
            self::Review => 'Проверить',
            self::Reply => 'Ответить',
            self::FollowUp => 'Контроль',
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
