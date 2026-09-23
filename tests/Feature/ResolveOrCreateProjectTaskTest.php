<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tasks\ResolveOrCreateProjectTask;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskSource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOrCreateProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function links_redmine_messages_with_same_issue_to_one_task(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        $first = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => '[Tekta - Bug #100] (Новая) Первый апдейт',
            'sender' => 'redmine@kelnik.pro',
        ]);
        $second = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
            'subject' => '[Tekta - Bug #100] (В работе) Второй апдейт',
            'sender' => 'redmine@kelnik.pro',
        ]);

        $resolver = app(ResolveOrCreateProjectTask::class);
        $task1 = $resolver->handle($first);
        $task2 = $resolver->handle($second);

        $this->assertNotNull($task1);
        $this->assertSame($task1->id, $task2?->id);
        $this->assertSame(ProjectTaskSource::Redmine, $task1->source);
        $this->assertSame('100', $task1->external_key);
        $this->assertSame(1, ProjectTask::query()->count());
        $this->assertSame($task1->id, $first->fresh()->project_task_id);
        $this->assertSame($task1->id, $second->fresh()->project_task_id);
    }

    #[Test]
    public function creates_email_thread_task_for_client_conversation(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Доработки',
            'external_thread_id' => 'thread-abc',
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Re: Доработки',
            'body' => 'Когда старт?',
        ]);

        $task = app(ResolveOrCreateProjectTask::class)->handle($message);

        $this->assertNotNull($task);
        $this->assertSame(ProjectTaskSource::EmailThread, $task->source);
        $this->assertSame('thread-abc', $task->external_key);
        $this->assertSame($project->id, $task->project_id);
    }

    #[Test]
    public function skips_empty_messages(): void
    {
        $message = Message::factory()->create([
            'sender' => '',
            'subject' => null,
            'body' => null,
            'classification' => MessageClassification::Unknown,
        ]);

        $task = app(ResolveOrCreateProjectTask::class)->handle($message);

        $this->assertNull($task);
        $this->assertSame(0, ProjectTask::query()->count());
    }
}
