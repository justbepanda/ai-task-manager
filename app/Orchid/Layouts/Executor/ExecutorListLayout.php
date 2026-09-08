<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Executor;

use App\Models\Executor;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ExecutorListLayout extends Table
{
    protected $target = 'executors';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->sort()->width('80'),
            TD::make('full_name', 'ФИО')
                ->render(fn (Executor $executor) => Link::make($executor->fullName())
                    ->route('platform.executors.edit', $executor)),
            TD::make('position', 'Должность'),
            TD::make('is_active', 'Активен')
                ->render(fn (Executor $executor) => $executor->is_active ? 'Да' : 'Нет'),
            TD::make('projects_count', 'Проекты')
                ->render(fn (Executor $executor) => (string) ($executor->projects_count ?? $executor->projects()->count())),
            TD::make('updated_at', 'Обновлён')
                ->render(fn (Executor $executor) => $executor->updated_at?->format('d.m.Y H:i')),
        ];
    }
}
