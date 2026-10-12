<?php

namespace App\AI\Exceptions;

use RuntimeException;

class AnalysisException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorType = 'analysis_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Transient provider issues that should be retried via the queue.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorType, [
            'rate_limit',
            'http_error',
            'timeout',
        ], true);
    }
}
