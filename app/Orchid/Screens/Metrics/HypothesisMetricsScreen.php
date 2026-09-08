<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Metrics;

use App\Domain\Metrics\HypothesisMetricsReport;
use App\Support\AdminLabels;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;

class HypothesisMetricsScreen extends Screen
{
    public function query(HypothesisMetricsReport $report): iterable
    {
        $snapshot = $report->snapshot();
        $rows = [];

        foreach ($snapshot['labels'] as $label => $value) {
            $rows[] = (object) [
                'label' => $label,
                'value' => $value,
            ];
        }

        $eventRows = [];
        foreach ($snapshot['from_events'] as $name => $count) {
            if ($count > 0) {
                $eventRows[] = (object) [
                    'name' => AdminLabels::metricEvent((string) $name),
                    'count' => $count,
                ];
            }
        }

        return [
            'metrics' => [
                'mail_received' => ['value' => $snapshot['from_events']['mail_received'] ?: $snapshot['live']['messages_total']],
                'client' => ['value' => $snapshot['from_events']['mail_classified_client'] ?: $snapshot['live']['messages_client']],
                'technical' => ['value' => $snapshot['from_events']['mail_classified_technical'] ?: $snapshot['live']['messages_technical']],
                'auto_matched' => ['value' => $snapshot['from_events']['project_auto_matched']],
                'unmatched' => ['value' => $snapshot['from_events']['project_unmatched'] ?: $snapshot['live']['messages_without_project']],
                'requires_manager' => ['value' => $snapshot['from_events']['event_requires_manager'] ?: $snapshot['live']['messages_requires_manager']],
                'drafts' => ['value' => $snapshot['from_events']['draft_created'] ?: $snapshot['live']['actions_with_draft']],
                'corrections' => ['value' => $snapshot['from_events']['analysis_corrected'] ?: $snapshot['live']['analysis_corrections']],
            ],
            'labelRows' => collect($rows),
            'eventRows' => collect($eventRows),
            'generated_at' => $snapshot['generated_at'],
        ];
    }

    public function name(): ?string
    {
        return 'Метрики гипотезы';
    }

    public function description(): ?string
    {
        return 'Экспериментальные метрики для оценки гипотезы MVP.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Обзор')->icon('bs.speedometer2')->route('platform.main'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::metrics([
                'Получено писем' => 'metrics.mail_received',
                'Клиентские' => 'metrics.client',
                'Технические' => 'metrics.technical',
                'Проект авто' => 'metrics.auto_matched',
                'Без проекта' => 'metrics.unmatched',
                'Нужен менеджер' => 'metrics.requires_manager',
                'Черновиков создано' => 'metrics.drafts',
                'Исправления' => 'metrics.corrections',
            ]),

            Layout::table('labelRows', [
                TD::make('label', 'Показатель')
                    ->render(fn (object $row) => e((string) $row->label)),
                TD::make('value', 'Значение')->width('120')
                    ->render(fn (object $row) => e((string) $row->value)),
            ])->title('Сводка по гипотезе'),

            Layout::table('eventRows', [
                TD::make('name', 'Тип события')
                    ->render(fn (object $row) => e((string) $row->name)),
                TD::make('count', 'Количество')->width('120')
                    ->render(fn (object $row) => e((string) $row->count)),
            ])->title('Журнал событий метрик'),
        ];
    }
}
