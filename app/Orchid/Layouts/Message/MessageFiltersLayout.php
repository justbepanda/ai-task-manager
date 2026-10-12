<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Message;

use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\Project;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;
use Orchid\Support\Color;

class MessageFiltersLayout extends Rows
{
    protected $title = 'Фильтры';

    protected function fields(): iterable
    {
        $projects = ['none' => 'Без проекта']
            + Project::query()->orderBy('name')->pluck('name', 'id')->all();

        return [
            Group::make([
                Select::make('filter.classification')
                    ->title('Классификация')
                    ->empty('Все')
                    ->options(MessageClassification::options()),

                Select::make('filter.processing_status')
                    ->title('Статус обработки')
                    ->empty('Все')
                    ->options(MessageProcessingStatus::options()),

                Select::make('filter.project_id')
                    ->title('Проект')
                    ->empty('Все')
                    ->options($projects),

                Select::make('filter.requires_manager')
                    ->title('Нужен менеджер')
                    ->empty('Все')
                    ->options([
                        '1' => 'Да',
                        '0' => 'Нет',
                    ]),
            ])->widthColumns('1fr 1fr 1fr 1fr'),

            Button::make('Применить фильтры')
                ->icon('bs.funnel')
                ->method('applyFilters')
                ->type(Color::PRIMARY),
        ];
    }
}
