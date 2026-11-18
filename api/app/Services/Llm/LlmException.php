<?php

namespace App\Services\Llm;

use RuntimeException;

class LlmException extends RuntimeException
{
    /** Retryable = transient (rate limit, 5xx, timeout); non-retryable = bad config, refusal, garbage output. */
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
