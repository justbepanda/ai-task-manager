<?php

namespace App\Hermes\Contracts;

use App\Hermes\DTO\HermesProposal;
use App\Models\Message;

/**
 * Hermes — constrained project-manager agent.
 *
 * May: read message, gather context, search/match projects, analyze,
 * propose state change, draft reply, propose ManagerAction fields.
 *
 * Must not: send mail, delete mail, mutate critical data without Laravel
 * validation, run arbitrary server commands.
 */
interface HermesAgent
{
    /**
     * Produce a structured proposal ("I think X happened").
     * Laravel validates and applies — Hermes never writes Project state itself.
     */
    public function propose(Message $message): HermesProposal;
}
