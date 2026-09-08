<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Project;

use App\Models\Project;
use App\Orchid\Layouts\Project\ProjectListLayout;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;

class ProjectListScreen extends Screen
{
    public function query(): iterable
    {
        return [
            'projects' => Project::query()
                ->with(['instruction', 'matchingRule'])
                ->latest('id')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Проекты';
    }

    public function description(): ?string
    {
        return 'CRUD проектов, инструкций и правил сопоставления писем.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Создать')
                ->icon('bs.plus-circle')
                ->route('platform.projects.create'),
        ];
    }

    public function layout(): iterable
    {
        return [
            ProjectListLayout::class,
        ];
    }
}
