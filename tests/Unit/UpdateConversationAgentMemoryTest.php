<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\DTO\AnalysisResult;
use App\Domain\Conversations\UpdateConversationAgentMemory;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateConversationAgentMemoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_message_facts_and_merges_conversation_state(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Thread',
            'agent_state' => [
                'topic' => 'Старая тема',
                'open_questions' => ['Старый вопрос'],
                'commitments' => [],
                'waiting_for' => null,
                'status' => 'active',
            ],
        ]);

        $message = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::Analyzed,
            'subject' => 'Re: доработки',
            'body' => 'Когда стартуем?',
        ]);

        $result = new AnalysisResult(
            classification: MessageClassification::Client,
            projectId: $project->id,
            confidence: 0.9,
            eventType: EventType::ClientQuestion,
            summary: 'Клиент спрашивает о старте работ',
            requiresManager: true,
            suggestedAction: ManagerActionType::Reply,
            draftReply: 'Стартуем завтра',
            reason: 'Нужен ответ по срокам',
        );

        $facts = app(UpdateConversationAgentMemory::class)->apply($message, $result, [
            'topic' => 'Старт работ',
            'open_questions' => ['Когда старт?'],
            'commitments' => ['Бюджет согласован'],
            'waiting_for' => 'ответ менеджера',
            'key_points' => ['Спрашивает про старт'],
        ]);

        $message->refresh();
        $conversation->refresh();

        $this->assertSame('Клиент спрашивает о старте работ', $facts['summary']);
        $this->assertSame('Старт работ', $message->agent_facts['topic'] ?? null);
        $this->assertContains('Когда старт?', $message->agent_facts['open_questions']);
        $this->assertSame('waiting_us', $conversation->agent_state['status'] ?? null);
        $this->assertSame($message->id, $conversation->agent_state['updated_from_message_id'] ?? null);
        $this->assertContains('Старый вопрос', $conversation->agent_state['open_questions']);
        $this->assertContains('Когда старт?', $conversation->agent_state['open_questions']);
        $this->assertContains('Бюджет согласован', $conversation->agent_state['commitments']);
    }

    #[Test]
    public function it_builds_facts_without_llm_block(): void
    {
        $message = Message::factory()->create([
            'subject' => 'Вопрос',
            'conversation_id' => null,
        ]);

        $result = new AnalysisResult(
            classification: MessageClassification::Client,
            projectId: null,
            confidence: 0.8,
            eventType: EventType::ClientQuestion,
            summary: 'Краткий summary',
            requiresManager: false,
            suggestedAction: ManagerActionType::NoAction,
            draftReply: null,
            reason: null,
        );

        $facts = app(UpdateConversationAgentMemory::class)->apply($message, $result, null);

        $this->assertSame('Краткий summary', $facts['summary']);
        $this->assertSame('Вопрос', $facts['topic']);
        $this->assertSame(['Краткий summary'], $facts['key_points']);
        $this->assertSame($facts, $message->fresh()->agent_facts);
    }
}
