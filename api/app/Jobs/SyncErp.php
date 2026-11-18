<?php

namespace App\Jobs;

use App\Services\Erp\ErpSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Pull vendors, POs, receipts and bill status from a company's ERP (one at a time per company). */
class SyncErp implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $companyId, public string $trigger = 'schedule') {}

    public function uniqueId(): string
    {
        return 'erp-sync:'.$this->companyId;
    }

    public function handle(ErpSync $sync): void
    {
        $sync->run($this->companyId, $this->trigger);
    }
}
