<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Task;

use App\Models\ProjectTask;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ProjectTaskListLayout extends Table
{
    protected $target = 'tasks';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->width('70'),
            TD::make('project', 'Проект')
                ->render(function (ProjectTask $task) {
                    if ($task->project === null) {
                        return '—';
                    }

                    return Link::make($task->project->name)
                        ->route('platform.projects.edit', $task->project);
                }),
            TD::make('task', 'Задача')
                ->render(fn (ProjectTask $task) => Link::make($task->displayLabel())
                    ->route('platform.tasks.view', $task)),
            TD::make('source', 'Источник')
                ->render(fn (ProjectTask $task) => e($task->source->label())),
            TD::make('status', 'Статус')
                ->render(fn (ProjectTask $task) => e($task->status->label())),
        ];
    }
}
