<?php

namespace App\Enums;

enum EventType: string
{
    case ClientQuestion = 'client_question';
    case ClientRequest = 'client_request';
    case ClientReportedBug = 'client_reported_bug';
    case ClientApproved = 'client_approved';
    case ClientRejected = 'client_rejected';
    case TaskCreated = 'task_created';
    case TaskUpdated = 'task_updated';
    case TaskCompleted = 'task_completed';
    case TechnicalNotification = 'technical_notification';
    case InformationOnly = 'information_only';
    case SiteLead = 'site_lead';

    public function label(): string
    {
        return match ($this) {
            self::ClientQuestion => 'Вопрос клиента',
            self::ClientRequest => 'Запрос клиента',
            self::ClientReportedBug => 'Сообщение об ошибке',
            self::ClientApproved => 'Клиент одобрил',
            self::ClientRejected => 'Клиент отклонил',
            self::TaskCreated => 'Задача создана',
            self::TaskUpdated => 'Задача обновлена',
            self::TaskCompleted => 'Задача завершена',
            self::TechnicalNotification => 'Техническое уведомление',
            self::InformationOnly => 'Только информация',
            self::SiteLead => 'Заявка с сайта',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
