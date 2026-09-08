<?php

namespace App\AI\Contracts;

use App\AI\DTO\MessageContext;

interface AiAnalyzer
{
    /**
     * Return structured analysis fields as an associative array (AGENTS §8).
     * AnalyzeMessage validates the payload into AnalysisResult.
     *
     * @return array<string, mixed>
     */
    public function analyze(MessageContext $context): array;
}
