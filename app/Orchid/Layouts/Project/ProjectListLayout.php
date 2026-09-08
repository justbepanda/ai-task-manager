<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Project;

use App\Models\Project;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ProjectListLayout extends Table
{
    protected $target = 'projects';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->sort()->width('80'),
            TD::make('name', 'Название')
                ->sort()
                ->render(fn (Project $project) => Link::make($project->name)
                    ->route('platform.projects.edit', $project)),
            TD::make('status', 'Статус')
                ->render(fn (Project $project) => e($project->status->label())),
            TD::make('updated_at', 'Обновлён')
                ->render(fn (Project $project) => $project->updated_at?->format('d.m.Y H:i')),
        ];
    }
}
