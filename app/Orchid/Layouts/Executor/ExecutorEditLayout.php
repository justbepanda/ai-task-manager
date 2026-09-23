<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Executor;

use App\Models\Project;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ExecutorEditLayout extends Rows
{
    protected function fields(): iterable
    {
        return [
            Input::make('executor.last_name')
                ->title('Фамилия')
                ->required()
                ->maxlength(255),

            Input::make('executor.first_name')
                ->title('Имя')
                ->required()
                ->maxlength(255),

            Input::make('executor.middle_name')
                ->title('Отчество')
                ->maxlength(255),

            Input::make('executor.position')
                ->title('Роль')
                ->help('Например: Менеджер, Разработчик. Заполняется автоматически при назначении менеджером проекта.')
                ->maxlength(255),

            TextArea::make('executor.emails')
                ->title('Email')
                ->rows(3)
                ->help('По одному адресу на строку'),

            Input::make('executor.telegram')
                ->title('Telegram')
                ->maxlength(255),

            Input::make('executor.phone')
                ->title('Телефон')
                ->maxlength(255),

            TextArea::make('executor.notes')
                ->title('Заметки')
                ->rows(3),

            CheckBox::make('executor.is_active')
                ->title('Активен')
                ->sendTrueOrFalse()
                ->placeholder('Сотрудник участвует в проектах'),

            Select::make('executor.project_ids.')
                ->fromQuery(Project::query()->orderBy('name'), 'name')
                ->multiple()
                ->title('Проекты'),
        ];
    }
}
