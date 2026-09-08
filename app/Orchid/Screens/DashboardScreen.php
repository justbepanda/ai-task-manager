<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Domain\Metrics\HypothesisMetricsReport;
use App\Enums\ManagerActionStatus;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
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
                'messages_total' => ['value' => Message::query()->count()],
                'messages_pending' => ['value' => Message::query()->where('processing_status', MessageProcessingStatus::Pending)->count()],
                'messages_needs_manual' => ['value' => Message::query()->where('processing_status', MessageProcessingStatus::NeedsManual)->count()],
                'messages_unknown' => ['value' => Message::query()->where('classification', MessageClassification::Unknown)->count()],
                'messages_ignored' => ['value' => Message::query()->where('classification', MessageClassification::Ignored)->count()],
                'events_attention' => ['value' => Message::query()->where('requires_manager', true)->count()],
                'actions_pending' => ['value' => ManagerAction::query()->where('status', ManagerActionStatus::Pending)->count()],
                'projects' => ['value' => Project::query()->count()],
            ],
            'hypothesis' => [
                'mail_received' => ['value' => $hypothesis['from_events']['mail_received'] ?: $hypothesis['live']['messages_total']],
                'client' => ['value' => $hypothesis['from_events']['mail_classified_client'] ?: $hypothesis['live']['messages_client']],
                'technical' => ['value' => $hypothesis['from_events']['mail_classified_technical'] ?: $hypothesis['live']['messages_technical']],
                'auto_matched' => ['value' => $hypothesis['from_events']['project_auto_matched']],
                'draft_unchanged' => ['value' => $hypothesis['from_events']['draft_approved_unchanged']],
                'draft_edited' => ['value' => $hypothesis['from_events']['draft_edited']],
                'rejected' => ['value' => $hypothesis['from_events']['action_rejected']],
                'corrections' => ['value' => $hypothesis['from_events']['analysis_corrected'] ?: $hypothesis['live']['analysis_corrections']],
            ],
            'pendingActions' => ManagerAction::query()
                ->with('project')
                ->where('status', ManagerActionStatus::Pending)
                ->latest()
                ->limit(8)
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
        return [
            Link::make('Входящие')->icon('bs.inbox')->route('platform.messages'),
            Link::make('Игнорируемые')->icon('bs.eye-slash')->route('platform.messages.ignored'),
            Link::make('Действия')->icon('bs.clipboard-check')->route('platform.manager-actions'),
            Link::make('Метрики гипотезы')->icon('bs.bar-chart')->route('platform.metrics.hypothesis'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::metrics([
                'Всего писем' => 'metrics.messages_total',
                'Ожидают обработки' => 'metrics.messages_pending',
                'Требуют ручной обработки' => 'metrics.messages_needs_manual',
                'Неизвестные' => 'metrics.messages_unknown',
                'Игнорируемые' => 'metrics.messages_ignored',
                'Требуют внимания' => 'metrics.events_attention',
                'Действия в ожидании' => 'metrics.actions_pending',
                'Проекты' => 'metrics.projects',
            ]),

            Layout::metrics([
                'Получено (гипотеза)' => 'hypothesis.mail_received',
                'Клиентские' => 'hypothesis.client',
                'Технические' => 'hypothesis.technical',
                'Проект авто' => 'hypothesis.auto_matched',
                'Черновик без правок' => 'hypothesis.draft_unchanged',
                'Черновик правлен' => 'hypothesis.draft_edited',
                'Отклонено' => 'hypothesis.rejected',
                'Исправления' => 'hypothesis.corrections',
            ]),

            Layout::table('pendingActions', [
                TD::make('id', 'ID')->width('80'),
                TD::make('project', 'Проект')
                    ->render(fn (ManagerAction $action) => e($action->project?->name ?? '—')),
                TD::make('reason', 'Причина')
                    ->render(fn (ManagerAction $action) => e(\Illuminate\Support\Str::limit((string) $action->reason, 80))),
                TD::make('open', '')
                    ->render(fn (ManagerAction $action) => Link::make('Открыть')
                        ->route('platform.manager-actions.view', $action)),
            ])->title('Ожидающие действия менеджера'),

            Layout::table('needsManualMessages', [
                TD::make('id', 'ID')->width('80'),
                TD::make('sender', 'Отправитель'),
                TD::make('subject', 'Тема')
                    ->render(fn (Message $message) => e(\Illuminate\Support\Str::limit((string) $message->subject, 80))),
                TD::make('open', '')
                    ->render(fn (Message $message) => Link::make('Открыть')
                        ->route('platform.messages.view', $message)),
            ])->title('Сообщения без проекта / требуют ручной обработки'),
        ];
    }
}
