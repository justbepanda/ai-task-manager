<?php

namespace Database\Seeders;

use App\Enums\EventType;
use App\Enums\MailSource;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoMailboxSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Project::query()->where('name', 'Интернет-магазин А')->firstOrFail();
        $corp = Project::query()->where('name', 'Корпоративный сайт')->firstOrFail();
        $redmine = Project::query()->where('name', 'Redmine / техподдержка')->firstOrFail();

        $shopThread = $this->conversation($shop, 'Сроки исправления корзины', 'seed:thread:shop-cart');
        $corpThread = $this->conversation($corp, 'Релиз главной страницы', 'seed:thread:corp-release');
        $redmineThread = $this->conversation($redmine, '[Redmine] #1024', 'seed:thread:redmine-1024');

        $clientMsg = $this->message([
            'external_id' => 'seed:msg:client-question-1',
            'conversation_id' => $shopThread->id,
            'project_id' => $shop->id,
            'sender' => 'client@romashka-shop.ru',
            'recipients' => ['manager@agency.local'],
            'subject' => 'Сроки исправления ошибки в корзине',
            'body' => "Добрый день!\nПодскажите, когда будет исправлена ошибка добавления товара в корзину на мобильной версии?\nС уважением, Пётр",
            'received_at' => Carbon::now()->subHours(6),
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Applied,
            'ai_summary' => 'Клиент спрашивает о сроках исправления ошибки корзины на mobile.',
            'ai_confidence' => 0.93,
            'requires_manager' => true,
        ]);

        $clientEvent = $this->event($clientMsg, $shop, EventType::ClientQuestion, 'Клиент спрашивает о сроках исправления ошибки корзины', 0.93, [
            'suggested_action' => 'reply',
            'draft_reply' => "Добрый день, Пётр!\nПроверяем проблему на mobile и вернёмся со сроками сегодня до 18:00.\nС уважением",
        ]);

        $this->managerAction($shop, $clientEvent, $clientMsg, ManagerActionType::Reply, ManagerActionStatus::Pending, 'Клиент ожидает информацию о сроках', "Добрый день, Пётр!\nПроверяем проблему на mobile и вернёмся со сроками сегодня до 18:00.\nС уважением");

        $this->logs($clientMsg, [
            ['step' => 'imported', 'status' => 'ok'],
            ['step' => 'classified', 'status' => 'ok', 'details' => ['classification' => 'client']],
            ['step' => 'project_matched', 'status' => 'ok', 'details' => ['project_id' => $shop->id]],
            ['step' => 'ai_analyzed', 'status' => 'ok'],
            ['step' => 'event_applied', 'status' => 'ok'],
            ['step' => 'manager_action_created', 'status' => 'ok'],
        ]);

        $followUp = $this->message([
            'external_id' => 'seed:msg:client-bug-2',
            'conversation_id' => $shopThread->id,
            'project_id' => $shop->id,
            'sender' => 'pm@romashka-shop.ru',
            'recipients' => ['manager@agency.local'],
            'subject' => 'Re: Сроки исправления ошибки в корзине',
            'body' => "Дополнительно: на iOS Safari корзина очищается после логина. Это критично для акций выходных.",
            'received_at' => Carbon::now()->subHours(3),
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Applied,
            'ai_summary' => 'Клиент сообщает критический баг очистки корзины на iOS Safari.',
            'ai_confidence' => 0.96,
            'requires_manager' => true,
        ]);

        $bugEvent = $this->event($followUp, $shop, EventType::ClientReportedBug, 'Критический баг корзины на iOS Safari', 0.96, [
            'suggested_action' => 'reply',
            'draft_reply' => "Добрый день!\nПриняли в работу как критичный инцидент. Вернём статус до конца дня.\nС уважением",
        ]);

        $this->managerAction($shop, $bugEvent, $followUp, ManagerActionType::Reply, ManagerActionStatus::Edited, 'Критичный баг — нужен ответ', "Добрый день!\nПриняли в работу как критичный инцидент. Вернём статус до конца дня.\nС уважением");

        $tech = $this->message([
            'external_id' => 'seed:msg:redmine-created-1',
            'conversation_id' => $redmineThread->id,
            'project_id' => $redmine->id,
            'sender' => 'redmine@tickets.example',
            'recipients' => ['ops@agency.local'],
            'subject' => '[Redmine] Bug #1024 created: Cart cleared on iOS',
            'body' => "Issue #1024 has been created.\nTracker: Bug\nPriority: High\nNo reply needed.",
            'received_at' => Carbon::now()->subHours(5),
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Applied,
            'ai_summary' => 'Техническое уведомление о создании задачи Redmine #1024.',
            'ai_confidence' => 0.99,
            'requires_manager' => false,
        ]);

        $this->event($tech, $redmine, EventType::TaskCreated, 'Создана задача Redmine #1024', 0.99, [
            'suggested_action' => 'no_action',
        ]);

        $this->logs($tech, [
            ['step' => 'imported', 'status' => 'ok'],
            ['step' => 'classified', 'status' => 'ok', 'details' => ['classification' => 'technical']],
            ['step' => 'project_matched', 'status' => 'ok', 'details' => ['project_id' => $redmine->id]],
            ['step' => 'ai_skipped', 'status' => 'ok', 'details' => ['reason' => 'technical']],
            ['step' => 'event_applied', 'status' => 'ok'],
        ]);

        $newsletter = $this->message([
            'external_id' => 'seed:msg:newsletter-1',
            'conversation_id' => null,
            'project_id' => null,
            'sender' => 'digest@saas-news.example',
            'recipients' => ['manager@agency.local'],
            'subject' => 'Weekly SaaS Newsletter — Unsubscribe anytime',
            'body' => "Your weekly digest of SaaS tips. Click unsubscribe to stop.",
            'received_at' => Carbon::now()->subDay(),
            'classification' => MessageClassification::Newsletter,
            'processing_status' => MessageProcessingStatus::Classified,
            'ai_summary' => null,
            'ai_confidence' => null,
            'requires_manager' => false,
        ]);

        $this->logs($newsletter, [
            ['step' => 'imported', 'status' => 'ok'],
            ['step' => 'classified', 'status' => 'ok', 'details' => ['classification' => 'newsletter']],
            ['step' => 'ai_skipped', 'status' => 'ok'],
        ]);

        $unknown = $this->message([
            'external_id' => 'seed:msg:unknown-1',
            'conversation_id' => null,
            'project_id' => null,
            'sender' => 'someone@random-domain.xyz',
            'recipients' => ['manager@agency.local'],
            'subject' => 'Вопрос по интеграции',
            'body' => "Здравствуйте, хотим обсудить интеграцию с вашей командой. Это по какому проекту?",
            'received_at' => Carbon::now()->subHours(2),
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'ai_summary' => null,
            'ai_confidence' => 0.41,
            'requires_manager' => true,
        ]);

        $this->logs($unknown, [
            ['step' => 'imported', 'status' => 'ok'],
            ['step' => 'classified', 'status' => 'ok', 'details' => ['classification' => 'unknown']],
            ['step' => 'project_matched', 'status' => 'skipped', 'details' => ['reason' => 'no_candidates']],
            ['step' => 'needs_manual', 'status' => 'ok'],
        ]);

        $corpMsg = $this->message([
            'external_id' => 'seed:msg:corp-request-1',
            'conversation_id' => $corpThread->id,
            'project_id' => $corp->id,
            'sender' => 'dig@sever.example',
            'recipients' => ['manager@agency.local', 'frontend@agency.local'],
            'cc' => ['content@sever.example'],
            'subject' => '[Website] Нужен релиз блока новостей',
            'body' => "Просьба запланировать релиз блока новостей на четверг. Макет во вложении (условно).",
            'received_at' => Carbon::now()->subHours(10),
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Applied,
            'ai_summary' => 'Клиент просит запланировать релиз блока новостей на четверг.',
            'ai_confidence' => 0.88,
            'requires_manager' => true,
        ]);

        $corpEvent = $this->event($corpMsg, $corp, EventType::ClientRequest, 'Запрос на релиз блока новостей', 0.88, [
            'suggested_action' => 'follow_up',
            'draft_reply' => "Добрый день!\nЗафиксировали релиз на четверг, подтвердим слот после оценки frontend.\nС уважением",
        ]);

        $this->managerAction($corp, $corpEvent, $corpMsg, ManagerActionType::FollowUp, ManagerActionStatus::Pending, 'Нужно подтвердить слот релиза', "Добрый день!\nЗафиксировали релиз на четверг, подтвердим слот после оценки frontend.\nС уважением");

        $pending = $this->message([
            'external_id' => 'seed:msg:pending-1',
            'conversation_id' => null,
            'project_id' => null,
            'sender' => 'new@example.org',
            'recipients' => ['manager@agency.local'],
            'subject' => 'Тестовое необработанное письмо',
            'body' => 'Это письмо ещё в статусе pending для демо очереди.',
            'received_at' => Carbon::now()->subMinutes(40),
            'classification' => null,
            'processing_status' => MessageProcessingStatus::Pending,
            'ai_summary' => null,
            'ai_confidence' => null,
            'requires_manager' => null,
        ]);

        $this->logs($pending, [
            ['step' => 'imported', 'status' => 'ok'],
        ]);
    }

    private function conversation(Project $project, string $subject, string $threadId): Conversation
    {
        return Conversation::query()->updateOrCreate(
            ['external_thread_id' => $threadId],
            [
                'project_id' => $project->id,
                'subject' => $subject,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(array $attributes): Message
    {
        return Message::query()->updateOrCreate(
            [
                'source' => MailSource::Seed,
                'external_id' => $attributes['external_id'],
            ],
            [
                'conversation_id' => $attributes['conversation_id'] ?? null,
                'project_id' => $attributes['project_id'] ?? null,
                'sender' => $attributes['sender'],
                'recipients' => $attributes['recipients'],
                'cc' => $attributes['cc'] ?? null,
                'subject' => $attributes['subject'],
                'body' => $attributes['body'],
                'received_at' => $attributes['received_at'],
                'headers' => ['X-Seed' => '1'],
                'metadata' => ['seed' => true],
                'classification' => $attributes['classification'],
                'processing_status' => $attributes['processing_status'],
                'ai_summary' => $attributes['ai_summary'],
                'ai_confidence' => $attributes['ai_confidence'],
                'requires_manager' => $attributes['requires_manager'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(
        Message $message,
        Project $project,
        EventType $type,
        string $summary,
        float $confidence,
        array $payload,
    ): Event {
        return Event::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'type' => $type,
            ],
            [
                'project_id' => $project->id,
                'summary' => $summary,
                'confidence' => $confidence,
                'payload' => $payload,
                'applied_at' => now(),
            ],
        );
    }

    private function managerAction(
        Project $project,
        Event $event,
        Message $message,
        ManagerActionType $type,
        ManagerActionStatus $status,
        string $reason,
        string $draft,
    ): ManagerAction {
        return ManagerAction::query()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'project_id' => $project->id,
                'message_id' => $message->id,
                'action_type' => $type,
                'status' => $status,
                'reason' => $reason,
                'draft_reply' => $draft,
                'final_reply' => null,
                'manager_notes' => null,
                'decided_at' => null,
            ],
        );
    }

    /**
     * @param  list<array{step: string, status: string, details?: array<string, mixed>}>  $entries
     */
    private function logs(Message $message, array $entries): void
    {
        ProcessingLog::query()->where('message_id', $message->id)->delete();

        foreach ($entries as $entry) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => $entry['step'],
                'status' => $entry['status'],
                'details' => $entry['details'] ?? null,
            ]);
        }
    }
}
