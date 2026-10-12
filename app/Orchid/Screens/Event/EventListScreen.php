<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Event;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\Project;
use App\Orchid\Layouts\Event\EventListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class EventListScreen extends Screen
{
    public function query(Request $request): iterable
    {
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        $query = Event::query()
            ->with(['project', 'message'])
            ->latest();

        if (! empty($filter['project_id'])) {
            if ($filter['project_id'] === 'none') {
                $query->whereNull('project_id');
            } else {
                $query->where('project_id', (int) $filter['project_id']);
            }
        }

        if (! empty($filter['type'])) {
            $query->where('type', $filter['type']);
        }

        $applied = $filter['applied'] ?? null;
        if ($applied === 'yes') {
            $query->whereNotNull('applied_at');
        } elseif ($applied === 'no') {
            $query->whereNull('applied_at');
        }

        return [
            'filter' => $filter,
            'events' => $query->paginate(30)->appends(['filter' => $filter]),
        ];
    }

    public function name(): ?string
    {
        return 'События';
    }

    public function description(): ?string
    {
        return 'Нормализованные события по письмам (Hermes → Laravel).';
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
                Select::make('filter.type')
                    ->title('Тип')
                    ->empty('Все')
                    ->options(EventType::options()),
                Select::make('filter.applied')
                    ->title('Применено')
                    ->empty('Все')
                    ->options([
                        'yes' => 'Да',
                        'no' => 'Нет',
                    ]),
            ]),
            EventListLayout::class,
        ];
    }

    public function applyFilters(Request $request)
    {
        return redirect()->route('platform.events', [
            'filter' => $request->input('filter', []),
        ]);
    }
}
