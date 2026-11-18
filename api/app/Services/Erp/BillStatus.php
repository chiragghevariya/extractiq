<?php

namespace App\Services\Erp;

use Illuminate\Support\Carbon;

/** A bill's state as the ERP reports it, reduced to draft | posted | paid | cancelled. */
final class BillStatus
{
    public const STATES = ['draft', 'posted', 'paid', 'cancelled'];

    public function __construct(
        public readonly string $state,
        public readonly ?Carbon $paidAt = null,
        public readonly ?string $detail = null,
    ) {}
}
