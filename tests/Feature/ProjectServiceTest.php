<?php

namespace Tests\Feature;

use App\Domain\Projects\ProjectService;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_project_with_instruction_and_matching_rules(): void
    {
        $project = app(ProjectService::class)->create([
            'name' => 'Интернет-магазин А',
            'description' => 'Клиентский проект',
            'status' => ProjectStatus::Active,
            'instruction_body' => 'Клиент — ООО Ромашка.',
            'client_emails' => "client@example.com\nother@example.com",
            'staff_emails' => 'dev@company.com',
            'domains' => 'example.com, shop.ru',
            'subject_patterns' => '[Redmine]',
            'keywords' => "сроки\nбаг",
            'technical_sources' => 'redmine:123',
            'custom_rules' => 'ignore newsletters',
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Интернет-магазин А',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('project_instructions', [
            'project_id' => $project->id,
            'body' => 'Клиент — ООО Ромашка.',
        ]);

        $project->load('matchingRule');

        $this->assertSame(['client@example.com', 'other@example.com'], $project->matchingRule->client_emails);
        $this->assertSame(['dev@company.com'], $project->matchingRule->staff_emails);
        $this->assertSame(['example.com', 'shop.ru'], $project->matchingRule->domains);
        $this->assertSame(['[Redmine]'], $project->matchingRule->subject_patterns);
        $this->assertSame(['сроки', 'баг'], $project->matchingRule->keywords);
        $this->assertSame(['redmine:123'], $project->matchingRule->technical_sources);
        $this->assertSame(['ignore newsletters'], $project->matchingRule->custom_rules);
    }

    public function test_update_project_replaces_rules(): void
    {
        $service = app(ProjectService::class);
        $project = $service->create([
            'name' => 'Старое название',
            'status' => ProjectStatus::Active,
            'instruction_body' => 'Старая инструкция',
            'client_emails' => 'old@example.com',
            'domains' => 'old.com',
        ]);

        $service->update($project, [
            'name' => 'Новое название',
            'description' => 'Обновлено',
            'status' => ProjectStatus::Paused,
            'instruction_body' => 'Новая инструкция',
            'client_emails' => 'new@example.com',
            'staff_emails' => '',
            'domains' => 'new.com',
            'subject_patterns' => '',
            'keywords' => '',
            'technical_sources' => '',
            'custom_rules' => '',
        ]);

        $project->refresh()->load(['instruction', 'matchingRule']);

        $this->assertSame('Новое название', $project->name);
        $this->assertSame(ProjectStatus::Paused, $project->status);
        $this->assertSame('Новая инструкция', $project->instruction->body);
        $this->assertSame(['new@example.com'], $project->matchingRule->client_emails);
        $this->assertSame(['new.com'], $project->matchingRule->domains);
    }

    public function test_message_external_id_is_unique_per_source(): void
    {
        \App\Models\Message::factory()->create([
            'source' => \App\Enums\MailSource::Yandex,
            'external_id' => 'msg-001',
            'processing_status' => \App\Enums\MessageProcessingStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        \App\Models\Message::factory()->create([
            'source' => \App\Enums\MailSource::Yandex,
            'external_id' => 'msg-001',
        ]);
    }
}
