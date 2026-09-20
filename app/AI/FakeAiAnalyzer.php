<?php

namespace App\AI;

use App\AI\Contracts\AiAnalyzer;
use App\AI\DTO\MessageContext;
use App\AI\Exceptions\AnalysisException;
use App\Enums\EventType;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use Closure;
use Throwable;

class FakeAiAnalyzer implements AiAnalyzer
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    private ?Throwable $throw = null;

    private ?Closure $handler = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonPayload = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;
        $this->throw = null;
        $this->handler = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withJsonPayload(array $payload): self
    {
        $this->jsonPayload = $payload;

        return $this;
    }

    public function withException(Throwable $exception): self
    {
        $this->throw = $exception;
        $this->payload = null;
        $this->handler = null;

        return $this;
    }

    public function withHandler(Closure $handler): self
    {
        $this->handler = $handler;
        $this->payload = null;
        $this->throw = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(MessageContext $context): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->handler !== null) {
            $value = ($this->handler)($context);
            if (! is_array($value)) {
                throw new AnalysisException('FakeAiAnalyzer handler must return array', 'fake_error');
            }

            return $value;
        }

        if ($this->payload !== null) {
            return $this->payload;
        }

        return $this->defaultPayload($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $systemPrompt, string $userPrompt): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->jsonPayload !== null) {
            return $this->jsonPayload;
        }

        if ($this->payload !== null) {
            return $this->payload;
        }

        return [
            'groups' => [],
            'unmatched_message_ids' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPayload(MessageContext $context): array
    {
        $projectId = $context->message['project_id']
            ?? ($context->candidateProjectIds[0] ?? null);

        return [
            'classification' => MessageClassification::Client->value,
            'project_id' => $projectId,
            'confidence' => 0.9,
            'event_type' => EventType::ClientQuestion->value,
            'summary' => 'Клиент задал вопрос по проекту',
            'requires_manager' => true,
            'suggested_action' => ManagerActionType::Reply->value,
            'draft_reply' => 'Добрый день! Спасибо за обращение, уточняем информацию и ответим.',
            'reason' => 'Клиент ожидает ответ',
        ];
    }
}
