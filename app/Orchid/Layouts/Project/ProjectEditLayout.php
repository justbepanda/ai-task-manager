<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Project;

use App\Enums\ProjectStatus;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ProjectEditLayout extends Rows
{
    protected function fields(): iterable
    {
        return [
            Input::make('project.name')
                ->title('Название')
                ->required()
                ->maxlength(255),

            TextArea::make('project.description')
                ->title('Описание')
                ->rows(3),

            Select::make('project.status')
                ->title('Статус')
                ->fromEnum(ProjectStatus::class, 'label')
                ->required(),

            TextArea::make('project.instruction_body')
                ->title('Инструкция для AI')
                ->rows(8)
                ->help('Свободный текст особенностей проекта для анализа.'),

            TextArea::make('project.client_emails')
                ->title('Адреса клиентов')
                ->rows(3)
                ->help('По одному email на строку'),

            TextArea::make('project.staff_emails')
                ->title('Адреса сотрудников')
                ->rows(3)
                ->help('По одному email на строку'),

            TextArea::make('project.domains')
                ->title('Домены')
                ->rows(3)
                ->help('По одному на строку'),

            TextArea::make('project.subject_patterns')
                ->title('Шаблоны темы')
                ->rows(3)
                ->help('По одному на строку'),

            TextArea::make('project.keywords')
                ->title('Ключевые слова')
                ->rows(3)
                ->help('По одному на строку'),

            TextArea::make('project.technical_sources')
                ->title('Технические источники')
                ->rows(3)
                ->help('Например redmine:42 — по одному на строку'),

            TextArea::make('project.custom_rules')
                ->title('Дополнительные правила')
                ->rows(3)
                ->help('По одному на строку'),
        ];
    }
}
