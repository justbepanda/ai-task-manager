<?php

namespace Database\Seeders;

use App\Domain\Projects\ProjectService;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ProjectService::class);

        $projects = [
            [
                'name' => 'Интернет-магазин А',
                'description' => 'Клиентский e-commerce проект ООО Ромашка.',
                'status' => ProjectStatus::Active,
                'instruction_body' => <<<'TXT'
Клиент проекта — ООО Ромашка.
Основные контакты: client@romashka-shop.ru, pm@romashka-shop.ru
Письма с домена romashka-shop.ru обычно относятся к проекту.
Если клиент сообщает о критической ошибке оплаты/корзины — требуется участие менеджера.
Автоматические уведомления о заказах обычно не требуют ответа.
TXT,
                'client_emails' => "client@romashka-shop.ru\npm@romashka-shop.ru",
                'staff_emails' => "dev@agency.local\nsupport@agency.local",
                'domains' => "romashka-shop.ru\nshop.romashka.ru",
                'subject_patterns' => "[Shop]\nЗаказ #",
                'keywords' => "корзина\nоплата\nдоставка",
                'technical_sources' => '',
                'custom_rules' => 'Игнорировать чисто маркетинговые рассылки с subject Promo',
            ],
            [
                'name' => 'Корпоративный сайт',
                'description' => 'Сайт и корпоративный портал клиента.',
                'status' => ProjectStatus::Active,
                'instruction_body' => <<<'TXT'
Клиент — АО Север.
Контакты: dig@sever.example, content@sever.example
Письма про контент, SEO и релизы сайта относятся сюда.
Критичные инциденты доступности требуют менеджера.
TXT,
                'client_emails' => "dig@sever.example\ncontent@sever.example",
                'staff_emails' => 'frontend@agency.local',
                'domains' => 'sever.example',
                'subject_patterns' => '[Website]',
                'keywords' => "релиз\nконтент\nSEO",
                'technical_sources' => '',
                'custom_rules' => '',
            ],
            [
                'name' => 'Redmine / техподдержка',
                'description' => 'Технические уведомления и тикеты Redmine/Jira.',
                'status' => ProjectStatus::Active,
                'instruction_body' => <<<'TXT'
Технические уведомления Redmine/Jira по проекту support.
Автосоздание задач обычно не требует ответа менеджера.
Если в письме эскалация P1/P2 — требуется review.
TXT,
                'client_emails' => 'redmine@tickets.example',
                'staff_emails' => "ops@agency.local\nqa@agency.local",
                'domains' => "tickets.example\nredmine.agency.local",
                'subject_patterns' => "[Redmine]\n[Jira]",
                'keywords' => "issue\nticket\nP1\nP2",
                'technical_sources' => "redmine:42\njira:SUPPORT",
                'custom_rules' => 'Письма с From redmine@ обычно technical',
            ],
            [
                'name' => 'Рассылки / внутреннее',
                'description' => 'Сток для newsletter и внутренней переписки (не клиентские проекты).',
                'status' => ProjectStatus::Paused,
                'instruction_body' => 'Используется как sink для newsletter/personal при ручной классификации. AI-ответы клиенту не формировать.',
                'client_emails' => '',
                'staff_emails' => 'team@agency.local',
                'domains' => '',
                'subject_patterns' => "Unsubscribe\nNewsletter",
                'keywords' => "рассылка\nnewsletter",
                'technical_sources' => '',
                'custom_rules' => '',
            ],
        ];

        foreach ($projects as $data) {
            $existing = Project::query()->where('name', $data['name'])->first();

            if ($existing !== null) {
                $service->update($existing, $data);
            } else {
                $service->create($data);
            }
        }
    }
}
