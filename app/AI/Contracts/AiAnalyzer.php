<?php

namespace App\AI\Contracts;

use App\Hermes\Contracts\LlmProvider;

/**
 * @deprecated Prefer {@see LlmProvider} via Hermes. Kept as alias for existing bindings/tests.
 */
interface AiAnalyzer extends LlmProvider
{
}
