<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'Purchase order',
            self::GoodsReceipt => 'Goods receipt',
            default => ucfirst($this->value),
        };
    }

    /** Only invoices continue past approval into matching and the ERP. */
    public function flowsToErp(): bool
    {
        return $this === self::Invoice;
    }
}
