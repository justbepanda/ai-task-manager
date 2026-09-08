<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class RoleEditLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('role.name')
                ->type('text')
                ->max(255)
                ->required()
                ->title('Название')
                ->placeholder('Название')
                ->help('Отображаемое имя роли'),

            Input::make('role.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title('Системное имя')
                ->placeholder('например manager')
                ->help('Технический идентификатор роли в системе'),
        ];
    }
}
