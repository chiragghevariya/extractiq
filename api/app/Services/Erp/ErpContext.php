<?php

namespace App\Services\Erp;

use App\Models\ErpConnection;

/** Who a connector works for: the company, its connection row (mapping, tokens, settings). */
final class ErpContext
{
    public function __construct(public readonly int $companyId, public readonly ErpConnection $connection) {}

    public function mapping(): ErpMapping
    {
        return ErpMapping::for($this->connection);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->connection->settings[$key] ?? $default;
    }
}
