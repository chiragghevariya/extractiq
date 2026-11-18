<?php

namespace App\Services\Erp;

/**
 * One ERP behind a common interface. Sync methods return connector-neutral rows that
 * MasterData upserts (see ErpSync); pushVendorBill must be safe to call twice with the same
 * idempotency key (the document uuid).
 */
interface ErpConnector
{
    public function name(): string;

    /** What this ERP can do: sync_vendors, sync_purchase_orders, sync_receipts, bill_status, attachments. @return array<string, bool> */
    public function capabilities(): array;

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array;

    /** @return list<array{name: string, tax_id?: ?string, email?: ?string, default_currency?: ?string, erp_ref: string, aliases?: list<string>}> */
    public function syncVendors(): array;

    /**
     * @return list<array{header: array{po_number: string, vendor_erp_ref: ?string, vendor_name?: ?string, order_date?: ?string, expected_date?: ?string, currency?: ?string, status?: string, erp_ref?: ?string},
     *                    lines: list<array{line_no: int, sku: ?string, description: string, quantity: float, uom?: ?string, unit_price_minor: ?int, erp_ref?: ?string}>}>
     */
    public function syncPurchaseOrders(): array;

    /**
     * @return list<array{header: array{grn_number: string, po_number: string, received_date?: ?string, erp_ref?: ?string},
     *                    lines: list<array{line_no: int, po_line_erp_ref?: ?string, po_line_no?: ?int, sku?: ?string, description: string, quantity: float}>}>
     */
    public function syncReceipts(): array;

    public function pushVendorBill(ErpBill $bill): PushResult;

    /** Where the bill stands in the ERP, or null if this ERP can't say. */
    public function getBillStatus(string $ref): ?BillStatus;
}
