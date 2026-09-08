<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Client;

use App\Models\Project;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ClientContactEditLayout extends Rows
{
    protected function fields(): iterable
    {
        return [
            Input::make('company_name')
                ->title('Клиент')
                ->readonly(),

            Input::make('contact.last_name')
                ->title('Фамилия')
                ->required()
                ->maxlength(255),

            Input::make('contact.first_name')
                ->title('Имя')
                ->required()
                ->maxlength(255),

            Input::make('contact.middle_name')
                ->title('Отчество')
                ->maxlength(255),

            Input::make('contact.position')
                ->title('Должность')
                ->maxlength(255),

            TextArea::make('contact.emails')
                ->title('Email')
                ->rows(3)
                ->help('По одному адресу на строку'),

            Input::make('contact.telegram')
                ->title('Telegram')
                ->maxlength(255),

            Input::make('contact.phone')
                ->title('Телефон')
                ->maxlength(255),

            TextArea::make('contact.notes')
                ->title('Заметки')
                ->rows(3),

            Select::make('contact.project_ids.')
                ->fromQuery(Project::query()->orderBy('name'), 'name')
                ->multiple()
                ->title('Проекты')
                ->help('При привязке контакта компания также будет привязана к этим проектам.'),
        ];
    }
}
