<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Client;

use App\Models\Project;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ClientCompanyEditLayout extends Rows
{
    protected function fields(): iterable
    {
        return [
            Input::make('company.name')
                ->title('Название')
                ->required()
                ->maxlength(255),

            TextArea::make('company.description')
                ->title('Описание')
                ->rows(3),

            TextArea::make('company.notes')
                ->title('Заметки')
                ->rows(3),

            Select::make('company.project_ids.')
                ->fromQuery(Project::query()->orderBy('name'), 'name')
                ->multiple()
                ->title('Проекты')
                ->help('Компания может быть связана с проектами без контактов.'),
        ];
    }
}
