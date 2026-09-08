<?php

namespace App\Support;

/**
 * Human-readable Russian labels for Orchid admin UI.
 */
final class AdminLabels
{
    public static function metricEvent(string $name): string
    {
        return match ($name) {
            'mail_received' => 'Письмо получено',
            'mail_classified_client' => 'Классификация: клиентское',
            'mail_classified_technical' => 'Классификация: техническое',
            'mail_classified_newsletter' => 'Классификация: рассылка',
            'mail_classified_personal' => 'Классификация: личное',
            'mail_classified_ignored' => 'Классификация: игнорируемое',
            'mail_classified_unknown' => 'Классификация: неизвестное',
            'project_auto_matched' => 'Проект определён автоматически',
            'project_unmatched' => 'Проект не определён',
            'project_ambiguous' => 'Несколько кандидатов проектов',
            'event_requires_manager' => 'Требуется участие менеджера',
            'draft_created' => 'Черновик ответа создан',
            'draft_approved_unchanged' => 'Черновик принят без изменений',
            'draft_edited' => 'Черновик отредактирован',
            'action_rejected' => 'Предложение отклонено',
            'analysis_corrected' => 'Анализ исправлен менеджером',
            default => $name,
        };
    }

    public static function processingStep(string $step): string
    {
        return match ($step) {
            'imported' => 'Импорт',
            'classified' => 'Классификация',
            'project_matched' => 'Сопоставление с проектом',
            'ai_analyzed' => 'AI-анализ',
            'event_applied' => 'Событие применено',
            'event_apply_skipped' => 'Применение события пропущено',
            default => $step,
        };
    }

    public static function processingStatus(string $status): string
    {
        return match ($status) {
            'ok' => 'Успешно',
            'failed' => 'Ошибка',
            'ambiguous' => 'Неоднозначно',
            'unmatched' => 'Без совпадения',
            'skipped' => 'Пропущено',
            default => $status,
        };
    }

    public static function correctionField(string $field): string
    {
        return match ($field) {
            'classification' => 'Классификация',
            'project_id' => 'Проект',
            'draft_reply' => 'Черновик ответа',
            'suggested_action' => 'Предлагаемое действие',
            'requires_manager' => 'Требуется менеджер',
            'event_type' => 'Тип события',
            'summary' => 'Краткое описание',
            default => $field,
        };
    }
}
