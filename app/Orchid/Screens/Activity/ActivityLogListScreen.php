<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Activity;

use App\Models\ActivityLog;
use App\Models\ProcessingLog;
use App\Support\AdminLabels;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;

class ActivityLogListScreen extends Screen
{
    public function query(Request $request): iterable
    {
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        $activities = ActivityLog::query()
            ->with(['user', 'message'])
            ->latest('id');

        if (! empty($filter['category'])) {
            $activities->where('category', $filter['category']);
        }

        if (! empty($filter['status'])) {
            $activities->where('status', $filter['status']);
        }

        $aiSteps = [
            'ai_analyzed',
            'hermes_pipeline',
            'hermes_context',
            'hermes_proposed',
            'hermes_llm_skipped',
        ];

        $aiLogs = ProcessingLog::query()
            ->with('message')
            ->whereIn('step', $aiSteps)
            ->latest('id')
            ->limit(50)
            ->get();

        return [
            'filter' => $filter,
            'activities' => $activities->paginate(40)->appends(['filter' => $filter]),
            'aiLogs' => $aiLogs,
        ];
    }

    public function name(): ?string
    {
        return 'Журнал';
    }

    public function description(): ?string
    {
        return 'Системные действия (пересборка фильтров, синхронизация, LLM) и последние шаги AI по письмам.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Обзор')->icon('bs.speedometer2')->route('platform.main'),
            Link::make('Фильтры почты')->icon('bs.funnel')->route('platform.mail-filters'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Group::make([
                    Select::make('filter.category')
                        ->title('Категория')
                        ->empty('Все')
                        ->options([
                            'mail_filters' => 'Фильтры почты',
                            'llm' => 'LLM',
                            'mail' => 'Почта',
                            'system' => 'Система',
                        ]),
                    Select::make('filter.status')
                        ->title('Статус')
                        ->empty('Все')
                        ->options([
                            'ok' => 'Успешно',
                            'failed' => 'Ошибка',
                        ]),
                ])->widthColumns('1fr 1fr'),

                Button::make('Применить')
                    ->icon('bs.funnel')
                    ->method('applyFilters')
                    ->type(Color::PRIMARY),
            ])->title('Фильтр системных событий'),

            Layout::table('activities', [
                TD::make('created_at', 'Время')
                    ->render(fn (ActivityLog $log) => e($log->created_at?->format('d.m.Y H:i:s') ?? '—')),
                TD::make('category', 'Категория')
                    ->render(fn (ActivityLog $log) => e(AdminLabels::activityCategory($log->category))),
                TD::make('action', 'Действие')
                    ->render(fn (ActivityLog $log) => e(AdminLabels::activityAction($log->action))),
                TD::make('status', 'Статус')
                    ->render(fn (ActivityLog $log) => e(AdminLabels::processingStatus($log->status))),
                TD::make('summary', 'Описание')
                    ->render(fn (ActivityLog $log) => e((string) ($log->summary ?? '—'))),
                TD::make('user', 'Кто')
                    ->render(fn (ActivityLog $log) => e($log->user?->email ?? 'система')),
                TD::make('details', 'Детали')
                    ->render(fn (ActivityLog $log) => e(SensitiveDataSanitizer::summary($log->details))),
            ])->title('Системные события'),

            Layout::table('aiLogs', [
                TD::make('created_at', 'Время')
                    ->render(fn (ProcessingLog $log) => e($log->created_at?->format('d.m.Y H:i:s') ?? '—')),
                TD::make('message_id', 'Письмо')
                    ->render(function (ProcessingLog $log) {
                        if ($log->message_id === null) {
                            return '—';
                        }

                        return '<a href="'.e(route('platform.messages.view', $log->message_id)).'">#'
                            .e((string) $log->message_id).'</a>';
                    }),
                TD::make('step', 'Шаг')
                    ->render(fn (ProcessingLog $log) => e(AdminLabels::processingStep((string) $log->step))),
                TD::make('status', 'Статус')
                    ->render(fn (ProcessingLog $log) => e(AdminLabels::processingStatus((string) $log->status))),
                TD::make('details', 'Детали')
                    ->render(fn (ProcessingLog $log) => e(SensitiveDataSanitizer::summary($log->details))),
            ])->title('Последние AI / Hermes по письмам'),
        ];
    }

    public function applyFilters(Request $request)
    {
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        return redirect()->route('platform.activity-logs', ['filter' => $filter]);
    }
}
