<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Metrics;

use App\Domain\Metrics\HypothesisMetricsReport;
use App\Enums\ManagerActionStatus;
use App\Enums\MessageClassification;
use App\Orchid\Layouts\Dashboard\ClickableMetricsLayout;
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
            $eventName = $this->labelToEventName($label);
            $url = $eventName !== null ? $this->urlForMetricEvent($eventName) : null;
            $rows[] = (object) [
                'label' => $label,
                'value' => $value,
                'url' => $url,
            ];
        }

        $eventRows = [];
        foreach ($snapshot['from_events'] as $name => $count) {
            if ($count > 0) {
                $eventRows[] = (object) [
                    'name' => AdminLabels::metricEvent((string) $name),
                    'count' => $count,
                    'url' => $this->urlForMetricEvent((string) $name),
                ];
            }
        }

        $from = $snapshot['from_events'];
        $live = $snapshot['live'];

        return [
            'metrics' => [
                'mail_received' => [
                    'value' => $from['mail_received'] ?: $live['messages_total'],
                    'url' => route('platform.messages'),
                ],
                'client' => [
                    'value' => $from['mail_classified_client'] ?: $live['messages_client'],
                    'url' => $this->messagesUrl(['classification' => MessageClassification::Client->value]),
                ],
                'technical' => [
                    'value' => $from['mail_classified_technical'] ?: $live['messages_technical'],
                    'url' => $this->messagesUrl(['classification' => MessageClassification::Technical->value]),
                ],
                'auto_matched' => [
                    'value' => $from['project_auto_matched'],
                    'url' => route('platform.messages'),
                ],
                'unmatched' => [
                    'value' => $from['project_unmatched'] ?: $live['messages_without_project'],
                    'url' => $this->messagesUrl(['project_id' => 'none']),
                ],
                'requires_manager' => [
                    'value' => $from['event_requires_manager'] ?: $live['messages_requires_manager'],
                    'url' => $this->messagesUrl(['requires_manager' => '1']),
                ],
                'drafts' => [
                    'value' => $from['draft_created'] ?: $live['actions_with_draft'],
                    'url' => route('platform.manager-actions'),
                ],
                'corrections' => [
                    'value' => $from['analysis_corrected'] ?: $live['analysis_corrections'],
                    'url' => route('platform.messages'),
                ],
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
            new ClickableMetricsLayout([
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
                    ->render(fn (object $row) => $this->renderLinkedCell((string) $row->label, $row->url ?? null)),
                TD::make('value', 'Значение')->width('120')
                    ->render(fn (object $row) => $this->renderLinkedCell((string) $row->value, $row->url ?? null)),
            ])->title('Сводка по гипотезе'),

            Layout::table('eventRows', [
                TD::make('name', 'Тип события')
                    ->render(fn (object $row) => $this->renderLinkedCell((string) $row->name, $row->url ?? null)),
                TD::make('count', 'Количество')->width('120')
                    ->render(fn (object $row) => $this->renderLinkedCell((string) $row->count, $row->url ?? null)),
            ])->title('Журнал событий метрик'),
        ];
    }

    /**
     * @param  array<string, string>  $filter
     */
    private function messagesUrl(array $filter): string
    {
        return route('platform.messages', ['filter' => $filter]);
    }

    private function managerActionsUrl(?string $status = null): string
    {
        if ($status === null) {
            return route('platform.manager-actions');
        }

        return route('platform.manager-actions', [
            'filter' => ['status' => $status],
        ]);
    }

    private function urlForMetricEvent(string $name): ?string
    {
        return match ($name) {
            'mail_received' => route('platform.messages'),
            'mail_classified_client' => $this->messagesUrl(['classification' => MessageClassification::Client->value]),
            'mail_classified_site_lead' => $this->messagesUrl(['classification' => MessageClassification::SiteLead->value]),
            'mail_classified_technical' => $this->messagesUrl(['classification' => MessageClassification::Technical->value]),
            'mail_classified_newsletter' => $this->messagesUrl(['classification' => MessageClassification::Newsletter->value]),
            'mail_classified_personal' => $this->messagesUrl(['classification' => MessageClassification::Personal->value]),
            'mail_classified_ignored' => route('platform.messages.ignored'),
            'mail_classified_unknown' => $this->messagesUrl(['classification' => MessageClassification::Unknown->value]),
            'project_auto_matched',
            'project_auto_created',
            'project_auto_created_llm',
            'project_auto_assigned_llm' => route('platform.messages'),
            'project_unmatched' => $this->messagesUrl(['project_id' => 'none']),
            'project_ambiguous' => route('platform.messages.assign'),
            'event_requires_manager' => $this->messagesUrl(['requires_manager' => '1']),
            'draft_created' => $this->managerActionsUrl(),
            'draft_approved_unchanged' => $this->managerActionsUrl(ManagerActionStatus::Approved->value),
            'draft_edited' => $this->managerActionsUrl(ManagerActionStatus::Edited->value),
            'action_rejected' => $this->managerActionsUrl(ManagerActionStatus::Rejected->value),
            'analysis_corrected' => route('platform.messages'),
            default => null,
        };
    }

    private function labelToEventName(string $label): ?string
    {
        return match ($label) {
            'Получено писем' => 'mail_received',
            'Клиентские письма' => 'mail_classified_client',
            'Заявки с сайта' => 'mail_classified_site_lead',
            'Технические письма' => 'mail_classified_technical',
            'Рассылки' => 'mail_classified_newsletter',
            'Личные' => 'mail_classified_personal',
            'Игнорируемые' => 'mail_classified_ignored',
            'Неизвестные' => 'mail_classified_unknown',
            'Проект определён автоматически' => 'project_auto_matched',
            'Проект не определён' => 'project_unmatched',
            'Несколько кандидатов проектов' => 'project_ambiguous',
            'События с участием менеджера' => 'event_requires_manager',
            'Черновиков ответов создано' => 'draft_created',
            'Черновик принят без изменений' => 'draft_approved_unchanged',
            'Черновик отредактирован' => 'draft_edited',
            'Предложения отклонены' => 'action_rejected',
            'Исправления анализа менеджером' => 'analysis_corrected',
            default => null,
        };
    }

    private function renderLinkedCell(string $text, mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return e($text);
        }

        return '<a href="'.e($url).'" class="text-decoration-none">'.e($text).'</a>';
    }
}
