<?php

namespace App\AI\Exceptions;

class AnalysisValidationException extends AnalysisException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'validation_error', $previous);
    }
}
