<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ManagerAction;

use App\Enums\ManagerActionStatus;
use App\Models\ManagerAction;
use App\Orchid\Layouts\ManagerAction\ManagerActionListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class ManagerActionListScreen extends Screen
{
    public function query(Request $request): iterable
    {
        $status = $request->input('filter.status');

        $query = ManagerAction::query()
            ->with(['project', 'event', 'message'])
            ->latest();

        if ($status === null || $status === '') {
            $query->where('status', ManagerActionStatus::Pending);
            $status = ManagerActionStatus::Pending->value;
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        return [
            'filter' => ['status' => $status],
            'actions' => $query->paginate(30)->appends(['filter' => ['status' => $status]]),
        ];
    }

    public function name(): ?string
    {
        return 'Действия менеджера';
    }

    public function description(): ?string
    {
        return 'Очередь предложений AI: подтверждение, редактирование, отклонение.';
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
        return [
            Layout::rows([
                Select::make('filter.status')
                    ->title('Статус')
                    ->options(array_merge(
                        ['all' => 'Все'],
                        ManagerActionStatus::options(),
                    )),
            ]),
            ManagerActionListLayout::class,
        ];
    }

    public function applyFilters(Request $request)
    {
        return redirect()->route('platform.manager-actions', [
            'filter' => $request->input('filter', []),
        ]);
    }
}
