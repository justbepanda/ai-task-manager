<?php

namespace App\Hermes;

use App\AI\Services\BuildMessageContext;
use App\Hermes\Contracts\HermesAgent;
use App\Hermes\Contracts\LlmProvider;
use App\Hermes\DTO\HermesProposal;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Support\SensitiveDataSanitizer;

/**
 * Default Hermes: gather context → ask LLM provider → return structured proposal.
 *
 * Deterministic classification / project match may already be on the Message
 * (Laravel jobs). Hermes must not invent projects outside candidates and must
 * not apply DB changes — only propose.
 */
class DefaultHermesAgent implements HermesAgent
{
    public function __construct(
        private readonly BuildMessageContext $buildMessageContext,
        private readonly LlmProvider $llm,
    ) {}

    public function propose(Message $message): HermesProposal
    {
        $context = $this->buildMessageContext->handle($message);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_context',
            'status' => 'ok',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'candidate_project_ids' => $context->candidateProjectIds,
                'has_project' => $context->project !== null,
                'matching_hints' => $context->matchingHints,
            ]),
        ]);

        $payload = $this->llm->analyze($context);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'hermes_proposed',
            'status' => 'ok',
            'details' => SensitiveDataSanitizer::sanitizeDetails([
                'llm_provider' => $this->llm::class,
                'proposal_keys' => array_keys($payload),
            ]),
        ]);

        return new HermesProposal(context: $context, payload: $payload);
    }
}
