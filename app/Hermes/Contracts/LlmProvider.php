<?php

namespace App\Hermes\Contracts;

use App\AI\DTO\MessageContext;

/**
 * Thin LLM completion API used by Hermes.
 * Domain code must not call this directly for message analysis — go through HermesAgent.
 * completeJson may be used by Hermes tools (e.g. bulk project assignment suggestions).
 */
interface LlmProvider
{
    /**
     * @return array<string, mixed> structured JSON fields (not yet validated)
     */
    public function analyze(MessageContext $context): array;

    /**
     * Generic JSON completion for Hermes tools (assignment board, etc.).
     *
     * @return array<string, mixed>
     */
    public function completeJson(string $systemPrompt, string $userPrompt): array;

    /**
     * Model id used for the last completeJson/analyze call (primary or fallback).
     */
    public function lastModel(): ?string;
}
