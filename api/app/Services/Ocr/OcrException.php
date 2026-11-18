<?php

namespace App\Services\Ocr;

use RuntimeException;

class OcrException extends RuntimeException
{
    /** retryable=false fails the job immediately (bad config, unsupported input) instead of burning retries. */
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
