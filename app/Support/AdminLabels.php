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
            'project_auto_created' => 'Проект создан автоматически',
            'project_auto_created_llm' => 'Проект создан через LLM',
            'project_auto_assigned_llm' => 'Проект назначен через LLM',
            'project_unmatched' => 'Проект не определён',
            'project_ambiguous' => 'Несколько кандидатов проектов',
            'event_requires_manager' => 'Требуется участие менеджера',
            'draft_created' => 'Черновик ответа создан',
            'draft_approved_unchanged' => 'Черновик принят без изменений',
            'draft_edited' => 'Черновик отредактирован',
            'action_rejected' => 'Предложение отклонено',
            'analysis_corrected' => 'Анализ исправлен менеджером',
            default => self::humanizeSnake($name),
        };
    }

    public static function processingStep(string $step): string
    {
        return match ($step) {
            'imported' => 'Импорт',
            'classified' => 'Классификация',
            'project_matched' => 'Сопоставление с проектом',
            'project_auto_created' => 'Проект создан автоматически',
            'project_auto_assigned_llm' => 'Распределение через LLM',
            'redmine_people_applied' => 'Redmine: люди проекта',
            'redmine_feedback_requires_manager' => 'Redmine: обратная связь',
            'ai_analyzed' => 'AI-анализ',
            'hermes_pipeline' => 'Hermes: пайплайн',
            'hermes_context' => 'Hermes: контекст',
            'hermes_proposed' => 'Hermes: предложение',
            'hermes_llm_skipped' => 'Hermes: LLM пропущен',
            'event_applied' => 'Событие применено',
            'event_apply_skipped' => 'Применение события пропущено',
            default => self::humanizeSnake($step),
        };
    }

    public static function activityCategory(string $category): string
    {
        return match ($category) {
            'mail_filters' => 'Фильтры почты',
            'llm' => 'LLM',
            'mail' => 'Почта',
            'system' => 'Система',
            default => self::humanizeSnake($category),
        };
    }

    public static function activityAction(string $action): string
    {
        return match ($action) {
            'rebuild_ignored' => 'Пересборка игнорируемых',
            'assign_suggest' => 'Распределение (LLM)',
            'sync' => 'Синхронизация',
            'follow_up_scan' => 'Скан follow-up (тишина)',
            'admin_tool' => 'Сервисная команда',
            default => self::humanizeSnake($action),
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
            default => self::humanizeSnake($status),
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
            'sender_role' => 'Роль отправителя',
            default => self::humanizeSnake($field),
        };
    }

    public static function detailKey(string $key): string
    {
        return match ($key) {
            'project_id' => 'проект',
            'message_id' => 'письмо',
            'event_id' => 'событие',
            'name' => 'название',
            'confidence' => 'уверенность',
            'threshold' => 'порог',
            'aliases' => 'алиасы',
            'source' => 'источник',
            'classification' => 'классификация',
            'reasons' => 'причины',
            'candidates' => 'кандидаты',
            'ambiguous' => 'неоднозначно',
            'auto_created' => 'автосоздан',
            'processing_status' => 'статус обработки',
            'needs_llm' => 'нужен LLM',
            'llm_assign_attempted' => 'попытка LLM-распределения',
            'llm_assign_applied' => 'LLM-распределение применено',
            'stages' => 'этапы',
            'error' => 'ошибка',
            'reason' => 'причина',
            'payload' => 'данные',
            'author' => 'автор',
            'assignee' => 'назначен',
            'manager_executor_id' => 'менеджер',
            'assignee_executor_id' => 'исполнитель',
            'subject' => 'тема',
            'event_type' => 'тип события',
            'requires_manager' => 'нужен менеджер',
            'suggested_action' => 'действие',
            'ai_model' => 'модель AI',
            'agent' => 'агент',
            'ai_response' => 'ответ AI',
            'created' => 'создан',
            'proposed_name' => 'предложенное название',
            'redmine_subject' => 'тема Redmine',
            'redmine_feedback' => 'обратная связь',
            default => self::humanizeSnake($key),
        };
    }

    private static function humanizeSnake(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (! str_contains($value, '_') && ! preg_match('/[a-z]/', $value)) {
            return $value;
        }

        return str_replace('_', ' ', $value);
    }
}
