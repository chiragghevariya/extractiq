<?php

namespace App\Services\Erp;

use RuntimeException;

/** An ERP call failed. retryable=false fails the push immediately (bad data, bad credentials). */
class ErpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly array $response = [],
        public readonly array $request = [],
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
