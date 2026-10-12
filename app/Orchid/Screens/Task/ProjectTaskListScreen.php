<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Task;

use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Orchid\Layouts\Task\ProjectTaskListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class ProjectTaskListScreen extends Screen
{
    public function query(Request $request): iterable
    {
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        $query = ProjectTask::query()
            ->with(['project', 'managerExecutor'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (! empty($filter['project_id'])) {
            if ($filter['project_id'] === 'none') {
                $query->whereNull('project_id');
            } else {
                $query->where('project_id', (int) $filter['project_id']);
            }
        }

        if (! empty($filter['status'])) {
            $query->where('status', $filter['status']);
        }

        if (! empty($filter['source'])) {
            $query->where('source', $filter['source']);
        }

        return [
            'filter' => $filter,
            'tasks' => $query->paginate(30)->appends(['filter' => $filter]),
        ];
    }

    public function name(): ?string
    {
        return 'Задачи';
    }

    public function description(): ?string
    {
        return 'Задачи проектов: Redmine/Jira и клиентские переписки.';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Применить фильтр')
                ->icon('bs.funnel')
                ->method('applyFilters'),
        ];
    }

    public function layout(): iterable
    {
        $projects = ['none' => 'Без проекта']
            + Project::query()->orderBy('name')->pluck('name', 'id')->all();

        return [
            Layout::rows([
                Select::make('filter.project_id')
                    ->title('Проект')
                    ->empty('Все')
                    ->options($projects),
                Select::make('filter.source')
                    ->title('Источник')
                    ->empty('Все')
                    ->options(ProjectTaskSource::options()),
                Select::make('filter.status')
                    ->title('Статус')
                    ->empty('Все')
                    ->options(ProjectTaskStatus::options()),
            ]),
            ProjectTaskListLayout::class,
        ];
    }

    public function applyFilters(Request $request)
    {
        return redirect()->route('platform.tasks', [
            'filter' => $request->input('filter', []),
        ]);
    }
}
