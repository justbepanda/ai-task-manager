<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ApplyMailFiltersJob;
use App\Jobs\SyncYandexMailJob;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Event;
use App\Models\Executor;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrchidAdminScreensTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_open_dashboard(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.main')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Обзор');
    }

    #[Test]
    public function admin_can_open_project_list_and_create_project(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.projects')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Проекты')
            ->assertSee('Входящие');

        $this->screen('platform.projects.create')
            ->actingAs($admin)
            ->method('save', [
                'project' => [
                    'name' => 'Orchid Project',
                    'description' => 'From test',
                    'status' => ProjectStatus::Active->value,
                    'instruction_body' => 'Test instruction',
                    'client_emails' => "a@example.com\nb@example.com",
                    'staff_emails' => '',
                    'domains' => 'example.com',
                    'subject_patterns' => '',
                    'keywords' => '',
                    'technical_sources' => '',
                    'custom_rules' => '',
                ],
            ])
            ->assertOk();

        $project = Project::query()->where('name', 'Orchid Project')->first();
        $this->assertNotNull($project);
        $this->assertSame(['a@example.com', 'b@example.com'], $project->matchingRule->client_emails);
    }

    #[Test]
    public function admin_can_open_manager_actions_and_approve(): void
    {
        $admin = $this->makeAdmin();
        $action = $this->pendingAction('Approve me');

        $this->screen('platform.manager-actions')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Действия менеджера')
            ->assertSee('Approve me');

        $this->screen('platform.manager-actions.view', ['manager_action' => $action])
            ->actingAs($admin)
            ->method('approve', [
                'form' => [
                    'final_reply' => 'Final reply text',
                    'draft_reply' => 'Approve me',
                ],
            ])
            ->assertOk();

        $this->assertSame(ManagerActionStatus::Approved, $action->fresh()->status);
        $this->assertSame('Final reply text', $action->fresh()->final_reply);
    }

    #[Test]
    public function guest_is_redirected_from_admin(): void
    {
        $this->get(route('platform.main'))
            ->assertRedirect();
    }

    #[Test]
    public function admin_can_save_app_settings(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.settings')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Настройки')
            ->assertSee('Автоматически создавать проекты')
            ->assertSee('Автоматически распределять через LLM');

        $this->screen('platform.settings')
            ->actingAs($admin)
            ->method('save', [
                'settings' => [
                    'auto_create_projects' => '1',
                    'auto_create_confidence_threshold' => '0.90',
                    'auto_assign_projects_llm' => '1',
                    'auto_assign_llm_confidence_threshold' => '0.85',
                ],
            ])
            ->assertOk();

        $settings = \App\Models\AppSetting::current();
        $this->assertTrue($settings->auto_create_projects);
        $this->assertEqualsWithDelta(0.90, $settings->auto_create_confidence_threshold, 0.001);
        $this->assertTrue($settings->auto_assign_projects_llm);
        $this->assertEqualsWithDelta(0.85, $settings->auto_assign_llm_confidence_threshold, 0.001);
    }

    #[Test]
    public function admin_can_save_mail_filter_settings(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.mail-filters')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Фильтры почты');

        $this->screen('platform.mail-filters')
            ->actingAs($admin)
            ->method('save', [
                'settings' => [
                    'blocked_emails_text' => "noreply@\nmailer-daemon@",
                    'blocked_domains_text' => "id.yandex.ru\npassport.yandex.ru",
                    'subject_stop_words_text' => "Код подтверждения\nSecurity alert",
                    'body_stop_words_text' => "Яндекс ID",
                ],
            ])
            ->assertOk();

        $settings = \App\Models\MailFilterSetting::current();
        $this->assertSame(['noreply@', 'mailer-daemon@'], $settings->blocked_emails);
        $this->assertSame(['id.yandex.ru', 'passport.yandex.ru'], $settings->blocked_domains);
        $this->assertSame(['Код подтверждения', 'Security alert'], $settings->subject_stop_words);
        $this->assertSame(['Яндекс ID'], $settings->body_stop_words);
    }

    #[Test]
    public function admin_can_dispatch_mail_sync_from_inbox(): void
    {
        Queue::fake([SyncYandexMailJob::class]);
        $admin = $this->makeAdmin();

        $this->screen('platform.messages')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Синхронизировать почту');

        $this->screen('platform.messages')
            ->actingAs($admin)
            ->method('syncMail')
            ->assertOk();

        Queue::assertPushed(SyncYandexMailJob::class);
    }

    #[Test]
    public function admin_can_open_ignored_messages_list(): void
    {
        $admin = $this->makeAdmin();

        Message::factory()->create([
            'classification' => MessageClassification::Ignored,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => 'Ignored password mail',
            'sender' => 'noreply@id.yandex.ru',
        ]);

        $this->screen('platform.messages.ignored')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Игнорируемые')
            ->assertSee('Ignored password mail')
            ->assertSee('Пересобрать игнорируемые');
    }

    #[Test]
    public function admin_can_rebuild_ignored_from_ignored_list_and_mail_filters(): void
    {
        Queue::fake([ApplyMailFiltersJob::class]);
        $admin = $this->makeAdmin();

        $this->screen('platform.messages.ignored')
            ->actingAs($admin)
            ->method('rebuildIgnored')
            ->assertOk();

        $this->screen('platform.mail-filters')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Пересобрать игнорируемые');

        $this->screen('platform.mail-filters')
            ->actingAs($admin)
            ->method('rebuildIgnored')
            ->assertOk();

        Queue::assertPushed(ApplyMailFiltersJob::class, 2);
    }

    #[Test]
    public function admin_can_open_hypothesis_metrics_screen(): void
    {
        $admin = $this->makeAdmin();

        $this->screen('platform.metrics.hypothesis')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Метрики гипотезы');
    }

    #[Test]
    public function admin_can_open_client_and_executor_screens(): void
    {
        $admin = $this->makeAdmin();
        $project = Project::factory()->create(['name' => 'Directory Project']);
        $company = ClientCompany::factory()->create(['name' => 'ООО Экран']);
        $contact = ClientContact::factory()->create([
            'client_company_id' => $company->id,
            'last_name' => 'Тестов',
            'first_name' => 'Контакт',
        ]);
        $executor = Executor::factory()->create([
            'last_name' => 'Исполнителев',
            'first_name' => 'Работник',
        ]);

        $this->screen('platform.client-companies')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Клиенты')
            ->assertSee('ООО Экран');

        $this->screen('platform.client-companies.edit', ['company' => $company])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('ООО Экран')
            ->assertSee('Тестов Контакт');

        $this->screen('platform.client-companies.create')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Новый клиент');

        $this->screen('platform.client-companies.create')
            ->actingAs($admin)
            ->method('save', [
                'company' => [
                    'name' => 'Новый клиент Orchid',
                    'description' => 'desc',
                    'notes' => null,
                    'project_ids' => [$project->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('client_companies', ['name' => 'Новый клиент Orchid']);

        $this->screen('platform.client-contacts.create', ['company' => $company])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Новый контакт');

        $this->screen('platform.client-contacts.edit', ['contact' => $contact])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Тестов');

        $this->screen('platform.executors')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Исполнители')
            ->assertSee('Исполнителев Работник');

        $this->screen('platform.executors.create')
            ->actingAs($admin)
            ->method('save', [
                'executor' => [
                    'last_name' => 'Новиков',
                    'first_name' => 'Олег',
                    'middle_name' => null,
                    'position' => 'QA',
                    'emails' => "qa@agency.local\nqa2@agency.local",
                    'telegram' => null,
                    'phone' => null,
                    'notes' => null,
                    'is_active' => '1',
                    'project_ids' => [$project->id],
                ],
            ])
            ->assertOk();

        $created = Executor::query()->where('last_name', 'Новиков')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->projects()->whereKey($project->id)->exists());
        $this->assertSame(['qa@agency.local', 'qa2@agency.local'], $created->emails);

        $this->screen('platform.executors.edit', ['executor' => $executor])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Исполнителев');
    }

    #[Test]
    public function admin_can_open_message_view_with_journal(): void
    {
        $admin = $this->makeAdmin();
        $message = Message::factory()->create([
            'subject' => 'View journal mail',
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Classified,
            'requires_manager' => true,
        ]);

        $this->screen('platform.messages.view', ['message' => $message])
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Журнал обработки')
            ->assertSee('View journal mail')
            ->assertSee('Отправитель')
            ->assertSee('Тема')
            ->assertSee('Классификация')
            ->assertSee('Проект')
            ->assertSee('Изменить')
            ->assertDontSee('Ручная корректировка')
            ->assertDontSee('Роль отправителя');
    }

    private function pendingAction(string $reason): ManagerAction
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'processing_status' => MessageProcessingStatus::Applied,
            'classification' => MessageClassification::Client,
            'requires_manager' => true,
        ]);
        $event = Event::query()->create([
            'message_id' => $message->id,
            'project_id' => $project->id,
            'type' => EventType::ClientQuestion,
            'summary' => 'Summary',
            'confidence' => 0.9,
            'payload' => [],
            'applied_at' => now(),
        ]);

        return ManagerAction::query()->create([
            'project_id' => $project->id,
            'event_id' => $event->id,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::Reply,
            'status' => ManagerActionStatus::Pending,
            'reason' => $reason,
            'draft_reply' => 'Draft',
        ]);
    }
}
