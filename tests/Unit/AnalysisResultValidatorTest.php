<?php

namespace Tests\Unit;

use App\AI\DTO\MessageContext;
use App\AI\Exceptions\AnalysisValidationException;
use App\AI\Services\AnalysisResultValidator;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalysisResultValidatorTest extends TestCase
{
    private AnalysisResultValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AnalysisResultValidator;
    }

    #[Test]
    public function it_validates_a_complete_payload(): void
    {
        $context = $this->context(projectId: 17, candidates: [17]);

        $result = $this->validator->validate([
            'classification' => 'client',
            'project_id' => 17,
            'confidence' => 0.94,
            'event_type' => 'client_question',
            'summary' => 'Клиент спрашивает о сроках',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Добрый день!',
            'reason' => 'Ожидает ответ',
        ], $context);

        $this->assertSame(MessageClassification::Client, $result->classification);
        $this->assertSame(17, $result->projectId);
        $this->assertSame(0.94, $result->confidence);
        $this->assertSame(EventType::ClientQuestion, $result->eventType);
        $this->assertTrue($result->requiresManager);
        $this->assertSame(ManagerActionType::Reply, $result->suggestedAction);
        $this->assertSame('Добрый день!', $result->draftReply);
    }

    #[Test]
    public function unknown_event_type_fails(): void
    {
        $this->expectException(AnalysisValidationException::class);
        $this->expectExceptionMessage('Unknown event_type');

        $this->validator->validate($this->basePayload([
            'event_type' => 'not_a_real_event',
        ]), $this->context(projectId: 1, candidates: [1]));
    }

    #[Test]
    public function missing_project_id_is_allowed(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'project_id' => null,
            'suggested_action' => 'review',
            'draft_reply' => null,
            'requires_manager' => true,
        ]), $this->context(projectId: null, candidates: []));

        $this->assertNull($result->projectId);
    }

    #[Test]
    public function low_confidence_is_still_accepted(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'confidence' => 0.2,
            'suggested_action' => 'review',
            'draft_reply' => null,
        ]), $this->context(projectId: 5, candidates: [5]));

        $this->assertSame(0.2, $result->confidence);
    }

    #[Test]
    public function draft_reply_required_when_suggested_action_is_reply(): void
    {
        $this->expectException(AnalysisValidationException::class);
        $this->expectExceptionMessage('draft_reply is required');

        $this->validator->validate($this->basePayload([
            'suggested_action' => 'reply',
            'draft_reply' => null,
        ]), $this->context(projectId: 1, candidates: [1]));
    }

    #[Test]
    public function ai_project_id_outside_candidates_is_soft_ignored_when_message_has_no_project(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'project_id' => 999,
            'suggested_action' => 'review',
            'draft_reply' => null,
        ]), $this->context(projectId: null, candidates: [1, 2]));

        $this->assertNull($result->projectId);
    }

    #[Test]
    public function message_project_id_is_kept_even_if_ai_returns_another(): void
    {
        $result = $this->validator->validate($this->basePayload([
            'project_id' => 999,
            'suggested_action' => 'review',
            'draft_reply' => null,
        ]), $this->context(projectId: 7, candidates: [7]));

        $this->assertSame(7, $result->projectId);
    }

    #[Test]
    public function empty_summary_fails(): void
    {
        $this->expectException(AnalysisValidationException::class);

        $this->validator->validate($this->basePayload([
            'summary' => '  ',
            'suggested_action' => 'no_action',
            'draft_reply' => null,
            'requires_manager' => false,
        ]), $this->context(projectId: 1, candidates: [1]));
    }

    /**
     * @param  list<int>  $candidates
     */
    private function context(?int $projectId, array $candidates): MessageContext
    {
        return new MessageContext(
            message: [
                'id' => 1,
                'project_id' => $projectId,
                'classification' => 'client',
                'subject' => 'Hi',
                'body' => 'Body',
                'sender' => 'a@b.c',
            ],
            candidateProjectIds: $candidates,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'classification' => 'client',
            'project_id' => 1,
            'confidence' => 0.8,
            'event_type' => 'client_question',
            'summary' => 'Summary',
            'requires_manager' => true,
            'suggested_action' => 'reply',
            'draft_reply' => 'Draft',
            'reason' => 'Reason',
        ], $overrides);
    }
}
