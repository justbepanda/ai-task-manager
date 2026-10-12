<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\DTO\AnalysisResult;
use App\Domain\Projects\UpdateProjectAgentState;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateProjectAgentStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_project_agent_state_from_facts_and_result(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Active,
            'agent_state' => [
                'topic' => 'Старая тема',
                'open_questions' => ['Старый вопрос'],
                'waiting_for' => null,
            ],
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'subject' => 'Re: сроки',
            'agent_facts' => [
                'topic' => 'Сроки бага',
                'open_questions' => ['Когда фикс?'],
                'waiting_for' => 'ответ менеджера',
            ],
        ]);

        $result = new AnalysisResult(
            classification: MessageClassification::Client,
            projectId: $project->id,
            confidence: 0.9,
            eventType: EventType::ClientQuestion,
            summary: 'Клиент ждёт ETA',
            requiresManager: true,
            suggestedAction: ManagerActionType::Reply,
            draftReply: 'Проверяем',
            reason: 'Нужен ответ',
        );

        $state = app(UpdateProjectAgentState::class)->apply($project, $message, $result);
        $project->refresh();

        $this->assertSame('Сроки бага', $state['topic']);
        $this->assertSame('ответ менеджера', $state['waiting_for']);
        $this->assertSame('Клиент ждёт ETA', $state['last_summary']);
        $this->assertSame($message->id, $state['updated_from_message_id']);
        $this->assertContains('Старый вопрос', $project->agent_state['open_questions']);
        $this->assertContains('Когда фикс?', $project->agent_state['open_questions']);
        $this->assertSame($state, $project->agent_state);
    }

    #[Test]
    public function it_seeds_open_questions_from_reason_when_manager_required(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'subject' => 'Вопрос',
            'agent_facts' => null,
        ]);

        $result = new AnalysisResult(
            classification: MessageClassification::Client,
            projectId: $project->id,
            confidence: 0.8,
            eventType: EventType::ClientQuestion,
            summary: 'Краткий summary',
            requiresManager: true,
            suggestedAction: ManagerActionType::Review,
            draftReply: null,
            reason: 'Нужна проверка менеджера',
        );

        $state = app(UpdateProjectAgentState::class)->apply($project, $message, $result);

        $this->assertSame('Вопрос', $state['topic']);
        $this->assertSame(['Нужна проверка менеджера'], $state['open_questions']);
        $this->assertSame('Краткий summary', $state['last_summary']);
    }
}
