<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Services\BuildMessageContext;
use App\Domain\Classification\ClassifyAndMatchMessage;
use App\Domain\Projects\DTO\MatchResult;
use App\Domain\Projects\DTO\ProjectCandidate;
use App\Domain\Projects\ProjectMatcher;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisCorrection;
use App\Models\MailFilterSetting;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMatchingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HermesRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Empty global blacklist so ignored matcher does not throw / match unexpectedly.
        MailFilterSetting::query()->create([
            'blocked_emails' => [],
            'blocked_domains' => [],
            'subject_stop_words' => [],
            'body_stop_words' => [],
        ]);
    }

    public function test_needs_llm_for_client_with_candidates_but_no_project(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        ProjectMatchingRule::query()->create([
            'project_id' => $project->id,
            'client_emails' => ['client@acme.com'],
        ]);

        // Force matcher to return candidates without auto project_id (ambiguous-like).
        $matcher = Mockery::mock(ProjectMatcher::class);
        $matcher->shouldReceive('match')->andReturn(new MatchResult(
            projectId: null,
            ambiguous: true,
            candidates: [
                new ProjectCandidate(projectId: $project->id, score: 0.7, reasons: ['email']),
            ],
            reasons: ['ambiguous'],
        ));
        $this->app->instance(ProjectMatcher::class, $matcher);

        $message = Message::factory()->create([
            'sender' => 'client@acme.com',
            'subject' => 'Вопрос',
            'body' => 'Нужна помощь',
            'classification' => null,
            'project_id' => null,
            'processing_status' => MessageProcessingStatus::Pending,
        ]);

        $result = app(ClassifyAndMatchMessage::class)->handle($message);

        $this->assertTrue($result['needs_llm']);
        $this->assertNull($result['message']->project_id);
        $this->assertNotEmpty($result['message']->metadata['project_match']['candidates'] ?? []);
    }

    public function test_build_message_context_technical_mode(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Technical,
        ]);

        $context = app(BuildMessageContext::class)->handle($message);

        $this->assertSame('technical_summary', $context->analysisMode);
    }

    public function test_build_message_context_includes_conversation_agent_state(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = \App\Models\Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Thread',
            'agent_state' => [
                'topic' => 'Метрики сайта',
                'status' => 'waiting_us',
                'open_questions' => ['Куда переносить счётчики?'],
            ],
        ]);

        $prior = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Earlier',
            'body' => 'Длинное старое тело про метрики и оверлей',
            'ai_summary' => 'Обсуждение метрик',
            'agent_facts' => [
                'summary' => 'Обсуждение метрик',
                'topic' => 'Метрики',
                'open_questions' => ['Перенос в body?'],
                'key_points' => ['Метрики нужны'],
            ],
            'received_at' => now()->subDays(2),
        ]);

        $current = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Now',
            'body' => 'Ок, давайте',
            'received_at' => now()->subDay(),
        ]);

        $context = app(BuildMessageContext::class)->handle($current);

        $this->assertSame('Метрики сайта', $context->conversationAgentState['topic'] ?? null);
        $this->assertCount(1, $context->conversationMessages);
        $this->assertSame($prior->id, $context->conversationMessages[0]['id']);
        $this->assertSame('Обсуждение метрик', $context->conversationMessages[0]['ai_summary'] ?? null);
        $this->assertIsArray($context->conversationMessages[0]['agent_facts'] ?? null);
    }

    public function test_build_message_context_excludes_later_conversation_messages(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $conversation = \App\Models\Conversation::query()->create([
            'project_id' => $project->id,
            'subject' => 'Thread',
        ]);

        $older = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Older',
            'body' => 'Старое письмо',
            'received_at' => now()->subDays(10),
        ]);

        $current = Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Current',
            'body' => 'Удобнее в среду в 16:00',
            'received_at' => now()->subDays(5),
        ]);

        Message::factory()->create([
            'project_id' => $project->id,
            'conversation_id' => $conversation->id,
            'classification' => MessageClassification::Client,
            'subject' => 'Later',
            'body' => 'В выходные напишу первые комментарии',
            'received_at' => now()->subDay(),
        ]);

        $context = app(BuildMessageContext::class)->handle($current);

        $ids = collect($context->conversationMessages)->pluck('id')->all();
        $this->assertContains($older->id, $ids);
        $this->assertCount(1, $ids);
        $this->assertStringContainsString('Удобнее в среду', (string) $context->message['body']);
        $this->assertStringNotContainsString('выходные', (string) $context->message['body']);
    }

    public function test_build_message_context_uses_latest_reply_not_quoted_history(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
            'body' => "Ксения, добрый день!\nПо оверлею вижу, спасибо.\nПеренести ниже — что это значит?\n\n"
                ."From: Manager Sent: Yesterday\nСтарый вопрос: когда начинаем перенос на ВМ?",
        ]);

        $context = app(BuildMessageContext::class)->handle($message);

        $this->assertStringContainsString('По оверлею вижу', (string) $context->message['body']);
        $this->assertStringContainsString('Перенести ниже', (string) $context->message['body']);
        $this->assertStringNotContainsString('когда начинаем перенос', (string) $context->message['body']);
        $this->assertNotNull($context->message['thread_excerpt'] ?? null);
    }

    public function test_build_message_context_includes_analysis_corrections(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'project_id' => $project->id,
            'classification' => MessageClassification::Client,
        ]);

        AnalysisCorrection::query()->create([
            'message_id' => $message->id,
            'field' => 'project_id',
            'ai_value' => '1',
            'manager_value' => (string) $project->id,
        ]);

        $other = Message::factory()->create(['project_id' => $project->id]);
        AnalysisCorrection::query()->create([
            'message_id' => $other->id,
            'field' => 'classification',
            'ai_value' => 'unknown',
            'manager_value' => 'client',
        ]);

        $context = app(BuildMessageContext::class)->handle($message->fresh());

        $this->assertNotEmpty($context->analysisCorrections);
        $this->assertSame('project_id', $context->analysisCorrections[0]['field']);
        $this->assertNotEmpty($context->recentProjectCorrections);
        $this->assertSame('full', $context->analysisMode);
    }
}
