<?php

namespace App\Domain\Metrics;

use App\Enums\ManagerActionStatus;
use App\Enums\MessageClassification;
use App\Models\AnalysisCorrection;
use App\Models\HypothesisMetricEvent;
use App\Models\ManagerAction;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class HypothesisMetricsReport
{
    /**
     * Snapshot of §21 hypothesis metrics (event log + live table counts).
     *
     * @return array{
     *     generated_at: string,
     *     from_events: array<string, int>,
     *     live: array<string, int>,
     *     labels: array<string, int>
     * }
     */
    public function snapshot(): array
    {
        $fromEvents = $this->countsFromEvents();
        $live = $this->liveCounts();

        $labels = [
            'Получено писем' => $fromEvents['mail_received'] ?: $live['messages_total'],
            'Клиентские письма' => $fromEvents['mail_classified_client'] ?: $live['messages_client'],
            'Заявки с сайта' => $fromEvents['mail_classified_site_lead'] ?: $live['messages_site_lead'],
            'Технические письма' => $fromEvents['mail_classified_technical'] ?: $live['messages_technical'],
            'Рассылки' => $fromEvents['mail_classified_newsletter'],
            'Личные' => $fromEvents['mail_classified_personal'],
            'Игнорируемые' => $fromEvents['mail_classified_ignored'] ?: $live['messages_ignored'],
            'Неизвестные' => $fromEvents['mail_classified_unknown'] ?: $live['messages_unknown'],
            'Проект определён автоматически' => $fromEvents['project_auto_matched'],
            'Проект не определён' => $fromEvents['project_unmatched'] ?: $live['messages_without_project'],
            'Несколько кандидатов проектов' => $fromEvents['project_ambiguous'],
            'События с участием менеджера' => $fromEvents['event_requires_manager'] ?: $live['messages_requires_manager'],
            'Черновиков ответов создано' => $fromEvents['draft_created'] ?: $live['actions_with_draft'],
            'Черновик принят без изменений' => $fromEvents['draft_approved_unchanged'] ?: $live['actions_approved'],
            'Черновик отредактирован' => $fromEvents['draft_edited'] ?: $live['actions_edited'],
            'Предложения отклонены' => $fromEvents['action_rejected'] ?: $live['actions_rejected'],
            'Исправления анализа менеджером' => $fromEvents['analysis_corrected'] ?: $live['analysis_corrections'],
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'from_events' => $fromEvents,
            'live' => $live,
            'labels' => $labels,
        ];
    }

    /**
     * Phase 8 real-mailbox evaluation checklist counts.
     *
     * @return array{metrics: array<string, int|string>, checklist: list<array{item: string, status: string, value: int|string}>}
     */
    public function phase8Evaluation(): array
    {
        $snapshot = $this->snapshot();
        $live = $snapshot['live'];
        $events = $snapshot['from_events'];

        $checklist = [
            ['item' => 'Письма импортированы', 'status' => $live['messages_total'] > 0 ? 'ok' : 'todo', 'value' => $live['messages_total']],
            ['item' => 'Технические не считаются клиентскими', 'status' => $live['messages_technical'] >= 0 ? 'ok' : 'todo', 'value' => $live['messages_technical']],
            ['item' => 'Клиентские письма классифицированы', 'status' => $live['messages_client'] > 0 ? 'ok' : 'check', 'value' => $live['messages_client']],
            ['item' => 'Проекты сопоставлены', 'status' => $events['project_auto_matched'] > 0 || $live['messages_with_project'] > 0 ? 'ok' : 'check', 'value' => $events['project_auto_matched'] ?: $live['messages_with_project']],
            ['item' => 'Неизвестные / без проекта', 'status' => 'info', 'value' => $live['messages_unknown'].' / '.$live['messages_without_project']],
            ['item' => 'Требуют участия менеджера', 'status' => 'info', 'value' => $live['messages_requires_manager']],
            ['item' => 'Черновики ответов', 'status' => 'info', 'value' => $events['draft_created'] ?: $live['actions_with_draft']],
            ['item' => 'Исправления менеджером', 'status' => 'info', 'value' => $events['analysis_corrected'] ?: $live['analysis_corrections']],
            ['item' => 'События метрик записаны', 'status' => array_sum($events) > 0 ? 'ok' : 'todo', 'value' => array_sum($events)],
        ];

        return [
            'metrics' => $snapshot['labels'],
            'checklist' => $checklist,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countsFromEvents(): array
    {
        $names = [
            'mail_received',
            'mail_classified_client',
            'mail_classified_site_lead',
            'mail_classified_technical',
            'mail_classified_newsletter',
            'mail_classified_personal',
            'mail_classified_ignored',
            'mail_classified_unknown',
            'project_auto_matched',
            'project_unmatched',
            'project_ambiguous',
            'event_requires_manager',
            'draft_created',
            'draft_approved_unchanged',
            'draft_edited',
            'action_rejected',
            'analysis_corrected',
        ];

        $counts = array_fill_keys($names, 0);

        $rows = HypothesisMetricEvent::query()
            ->select('name', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('name')
            ->pluck('aggregate', 'name');

        foreach ($rows as $name => $count) {
            $counts[(string) $name] = (int) $count;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function liveCounts(): array
    {
        return [
            'messages_total' => Message::query()->count(),
            'messages_client' => Message::query()->where('classification', MessageClassification::Client)->count(),
            'messages_site_lead' => Message::query()->where('classification', MessageClassification::SiteLead)->count(),
            'messages_technical' => Message::query()->where('classification', MessageClassification::Technical)->count(),
            'messages_newsletter' => Message::query()->where('classification', MessageClassification::Newsletter)->count(),
            'messages_personal' => Message::query()->where('classification', MessageClassification::Personal)->count(),
            'messages_ignored' => Message::query()->where('classification', MessageClassification::Ignored)->count(),
            'messages_unknown' => Message::query()->where('classification', MessageClassification::Unknown)->count(),
            'messages_with_project' => Message::query()->whereNotNull('project_id')->count(),
            'messages_without_project' => Message::query()->whereNull('project_id')->count(),
            'messages_requires_manager' => Message::query()->where('requires_manager', true)->count(),
            'actions_with_draft' => ManagerAction::query()->whereNotNull('draft_reply')->where('draft_reply', '!=', '')->count(),
            'actions_approved' => ManagerAction::query()->where('status', ManagerActionStatus::Approved)->count(),
            'actions_edited' => ManagerAction::query()->where('status', ManagerActionStatus::Edited)->count(),
            'actions_rejected' => ManagerAction::query()->where('status', ManagerActionStatus::Rejected)->count(),
            'analysis_corrections' => AnalysisCorrection::query()->count(),
        ];
    }
}
