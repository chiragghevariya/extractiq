<?php

namespace App\Support;

/** Holds the company id for the current request (bound by the auth middleware). */
class Tenant
{
    private ?int $companyId = null;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function run(int $companyId, callable $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;
        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
