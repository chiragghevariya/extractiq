<?php

namespace App\Services\Erp;

final class PushResult
{
    public function __construct(
        public readonly string $externalRef,
        public readonly array $response = [],
        public readonly bool $alreadyExisted = false,
        public readonly ?string $url = null,       // deep link to the bill in the ERP
        public readonly array $request = [],       // what was sent (for the push log)
        public readonly ?int $httpStatus = null,
        public readonly string $state = 'draft',   // the bill's state right after the push
    ) {}
}
