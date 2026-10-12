<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Domain\Metrics\HypothesisMetricsReport;
use App\Enums\ManagerActionStatus;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use App\Orchid\Layouts\Dashboard\ClickableMetricsLayout;
use App\Orchid\Layouts\Event\EventListLayout;
use App\Orchid\Layouts\ManagerAction\ManagerActionListLayout;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;

class DashboardScreen extends Screen
{
    public function query(HypothesisMetricsReport $report): iterable
    {
        $hypothesis = $report->snapshot();

        return [
            'metrics' => [
                'messages_total' => [
                    'value' => Message::query()->count(),
                    'url' => route('platform.messages'),
                ],
                'messages_pending' => [
                    'value' => Message::query()->where('processing_status', MessageProcessingStatus::Pending)->count(),
                    'url' => route('platform.messages', [
                        'filter' => ['processing_status' => MessageProcessingStatus::Pending->value],
                    ]),
                ],
                'messages_needs_manual' => [
                    'value' => Message::query()->where('processing_status', MessageProcessingStatus::NeedsManual)->count(),
                    'url' => route('platform.messages', [
                        'filter' => ['processing_status' => MessageProcessingStatus::NeedsManual->value],
                    ]),
                ],
                'messages_unknown' => [
                    'value' => Message::query()->where('classification', MessageClassification::Unknown)->count(),
                    'url' => route('platform.messages', [
                        'filter' => ['classification' => MessageClassification::Unknown->value],
                    ]),
                ],
                'messages_ignored' => [
                    'value' => Message::query()->where('classification', MessageClassification::Ignored)->count(),
                    'url' => route('platform.messages.ignored'),
                ],
                'events_attention' => [
                    'value' => Message::query()->where('requires_manager', true)->count(),
                    'url' => route('platform.messages', [
                        'filter' => ['requires_manager' => '1'],
                    ]),
                ],
                'actions_pending' => [
                    'value' => ManagerAction::query()->where('status', ManagerActionStatus::Pending)->count(),
                    'url' => route('platform.manager-actions'),
                ],
                'projects' => [
                    'value' => Project::query()->count(),
                    'url' => route('platform.projects'),
                ],
            ],
            'hypothesis' => [
                'mail_received' => [
                    'value' => $hypothesis['from_events']['mail_received'] ?: $hypothesis['live']['messages_total'],
                    'url' => route('platform.metrics.hypothesis'),
                ],
                'client' => [
                    'value' => $hypothesis['from_events']['mail_classified_client'] ?: $hypothesis['live']['messages_client'],
                    'url' => route('platform.messages', [
                        'filter' => ['classification' => MessageClassification::Client->value],
                    ]),
                ],
                'technical' => [
                    'value' => $hypothesis['from_events']['mail_classified_technical'] ?: $hypothesis['live']['messages_technical'],
                    'url' => route('platform.messages', [
                        'filter' => ['classification' => MessageClassification::Technical->value],
                    ]),
                ],
                'auto_matched' => [
                    'value' => $hypothesis['from_events']['project_auto_matched'],
                    'url' => route('platform.metrics.hypothesis'),
                ],
                'draft_unchanged' => [
                    'value' => $hypothesis['from_events']['draft_approved_unchanged'],
                    'url' => route('platform.manager-actions', [
                        'filter' => ['status' => ManagerActionStatus::Approved->value],
                    ]),
                ],
                'draft_edited' => [
                    'value' => $hypothesis['from_events']['draft_edited'],
                    'url' => route('platform.manager-actions', [
                        'filter' => ['status' => ManagerActionStatus::Edited->value],
                    ]),
                ],
                'rejected' => [
                    'value' => $hypothesis['from_events']['action_rejected'],
                    'url' => route('platform.manager-actions', [
                        'filter' => ['status' => ManagerActionStatus::Rejected->value],
                    ]),
                ],
                'corrections' => [
                    'value' => $hypothesis['from_events']['analysis_corrected'] ?: $hypothesis['live']['analysis_corrections'],
                    'url' => route('platform.metrics.hypothesis'),
                ],
            ],
            'pendingActions' => ManagerAction::query()
                ->with(['project', 'event', 'message'])
                ->where('status', ManagerActionStatus::Pending)
                ->latest()
                ->limit(8)
                ->get(),
            'recentEvents' => Event::query()
                ->with(['project', 'message'])
                ->latest()
                ->limit(10)
                ->get(),
            'needsManualMessages' => Message::query()
                ->where('processing_status', MessageProcessingStatus::NeedsManual)
                ->latest('received_at')
                ->limit(8)
                ->get(),
        ];
    }

    public function name(): ?string
    {
        return 'Обзор';
    }

    public function description(): ?string
    {
        return 'Сводка по входящим письмам, событиям и предложениям AI.';
    }

    public function commandBar(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        return [
            new ClickableMetricsLayout([
                'Всего писем' => 'metrics.messages_total',
                'Ожидают обработки' => 'metrics.messages_pending',
                'Требуют ручной обработки' => 'metrics.messages_needs_manual',
                'Неизвестные' => 'metrics.messages_unknown',
                'Игнорируемые' => 'metrics.messages_ignored',
                'Требуют внимания' => 'metrics.events_attention',
                'Действия в ожидании' => 'metrics.actions_pending',
                'Проекты' => 'metrics.projects',
            ]),

            new ClickableMetricsLayout([
                'Получено (гипотеза)' => 'hypothesis.mail_received',
                'Клиентские' => 'hypothesis.client',
                'Технические' => 'hypothesis.technical',
                'Проект авто' => 'hypothesis.auto_matched',
                'Черновик без правок' => 'hypothesis.draft_unchanged',
                'Черновик правлен' => 'hypothesis.draft_edited',
                'Отклонено' => 'hypothesis.rejected',
                'Исправления' => 'hypothesis.corrections',
            ]),

            Layout::block([
                new ManagerActionListLayout('pendingActions'),
            ])
                ->title('Ожидающие действия менеджера')
                ->vertical()
                ->commands([
                    Link::make('Все действия')
                        ->icon('bs.clipboard-check')
                        ->route('platform.manager-actions'),
                ]),

            Layout::block([
                new EventListLayout('recentEvents'),
            ])
                ->title('Лента событий')
                ->vertical()
                ->commands([
                    Link::make('Все события')
                        ->icon('bs.lightning')
                        ->route('platform.events'),
                ]),

            Layout::block([
                Layout::table('needsManualMessages', [
                    TD::make('id', 'ID')->width('80')
                        ->render(fn (Message $message) => Link::make((string) $message->id)
                            ->route('platform.messages.view', $message)),
                    TD::make('sender', 'Отправитель'),
                    TD::make('subject', 'Тема')
                        ->render(fn (Message $message) => Link::make(\Illuminate\Support\Str::limit((string) $message->subject, 80))
                            ->route('platform.messages.view', $message)),
                    TD::make('open', '')
                        ->render(fn (Message $message) => Link::make('Открыть')
                            ->route('platform.messages.view', $message)),
                ]),
            ])
                ->title('Сообщения без проекта / требуют ручной обработки')
                ->vertical()
                ->commands([
                    Link::make('Распределение')
                        ->icon('bs.diagram-3')
                        ->route('platform.messages.assign'),
                    Link::make('Входящие')
                        ->icon('bs.inbox')
                        ->route('platform.messages'),
                ]),
        ];
    }
}
