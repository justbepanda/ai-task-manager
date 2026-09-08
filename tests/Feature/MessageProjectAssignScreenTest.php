<?php

namespace Tests\Feature;

use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessageProjectAssignScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_and_attach_creates_project_and_binds_messages(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $message = $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта - Bug #1] Ping',
            'body' => 'Issue update',
        ]);

        $this->screen('platform.messages.assign')
            ->actingAs($admin)
            ->method('createAndAttach', [
                'name' => 'Tekta',
                'message_ids' => [$message->id],
                'aliases' => ['Tekta', 'Текта'],
            ])
            ->assertOk();

        $project = Project::query()->where('name', 'Tekta')->first();
        $this->assertNotNull($project);
        $this->assertSame($project->id, $message->fresh()->project_id);
        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertContains('Tekta', $project->matchingRule?->keywords ?? []);
    }

    #[Test]
    public function assign_screen_renders_new_redmine_groups(): void
    {
        $admin = $this->makeAdmin();
        $this->unassignedMessage([
            'sender' => 'redmine@kelnik.pro',
            'subject' => '[Tekta, Текта - Bug #2] Ping',
            'body' => 'Issue',
        ]);

        $this->screen('platform.messages.assign')
            ->actingAs($admin)
            ->display()
            ->assertOk()
            ->assertSee('Tekta (новый)')
            ->assertSee('Создать «Tekta» и привязать выбранные');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function unassignedMessage(array $attributes): Message
    {
        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => $attributes['subject'] ?? 'Subject',
            'external_thread_id' => fake()->uuid(),
        ]);

        return Message::factory()->create(array_merge([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'processing_status' => MessageProcessingStatus::Pending,
            'classification' => null,
            'recipients' => ['manager@yandex.ru'],
            'cc' => [],
            'headers' => [],
        ], $attributes));
    }
}
