<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Executor;

use App\Models\Executor;
use App\Orchid\Layouts\Executor\ExecutorListLayout;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;

class ExecutorListScreen extends Screen
{
    public function query(): iterable
    {
        return [
            'executors' => Executor::query()
                ->withCount('projects')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Исполнители';
    }

    public function description(): ?string
    {
        return 'Сотрудники нашей компании, участвующие в проектах.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Создать')
                ->icon('bs.plus-circle')
                ->route('platform.executors.create'),
        ];
    }

    public function layout(): iterable
    {
        return [
            ExecutorListLayout::class,
        ];
    }
}
