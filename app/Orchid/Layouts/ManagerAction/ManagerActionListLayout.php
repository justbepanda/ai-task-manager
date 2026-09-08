<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ManagerAction;

use App\Models\ManagerAction;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ManagerActionListLayout extends Table
{
    protected $target = 'actions';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->width('70'),
            TD::make('project', 'Проект')
                ->render(fn (ManagerAction $action) => e($action->project?->name ?? '—')),
            TD::make('action_type', 'Действие')
                ->render(fn (ManagerAction $action) => e($action->action_type->label())),
            TD::make('status', 'Статус')
                ->render(fn (ManagerAction $action) => e($action->status->label())),
            TD::make('reason', 'Причина')
                ->render(fn (ManagerAction $action) => e(\Illuminate\Support\Str::limit((string) $action->reason, 70))),
            TD::make('open', '')
                ->render(fn (ManagerAction $action) => Link::make('Открыть')
                    ->route('platform.manager-actions.view', $action)),
        ];
    }
}
