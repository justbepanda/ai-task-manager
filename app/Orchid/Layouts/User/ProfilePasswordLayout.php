<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Layouts\Rows;

class ProfilePasswordLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Password::make('old_password')
                ->placeholder('Введите текущий пароль')
                ->title('Текущий пароль')
                ->help('Пароль, установленный сейчас.'),

            Password::make('password')
                ->placeholder('Введите новый пароль')
                ->title('Новый пароль'),

            Password::make('password_confirmation')
                ->placeholder('Повторите новый пароль')
                ->title('Подтверждение пароля')
                ->help('Надёжный пароль — не менее 15 символов или не менее 8 символов, включая цифру и строчную букву.'),
        ];
    }
}
