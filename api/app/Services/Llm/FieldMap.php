<?php

namespace App\Services\Llm;

/** Which schema field fills which document column, per document type. */
final class FieldMap
{
    public const COLUMNS = [
        'invoice' => [
            'vendor_name' => 'vendor.name', 'document_number' => 'invoice_number', 'document_date' => 'invoice_date', 'due_date' => 'due_date',
            'po_number' => 'po_number', 'currency' => 'currency', 'subtotal' => 'subtotal', 'tax' => 'tax_total', 'shipping' => 'shipping',
            'total' => 'total', 'payment_terms' => 'payment_terms', 'vendor_tax_id' => 'vendor.tax_id',
        ],
        'purchase_order' => [
            'vendor_name' => 'vendor.name', 'document_number' => 'po_number', 'po_number' => 'po_number', 'document_date' => 'order_date',
            'currency' => 'currency', 'subtotal' => 'subtotal', 'tax' => 'tax_total', 'shipping' => 'shipping', 'total' => 'total',
            'payment_terms' => 'payment_terms', 'vendor_tax_id' => 'vendor.tax_id',
        ],
        'goods_receipt' => [
            'vendor_name' => 'vendor.name', 'document_number' => 'grn_number', 'po_number' => 'po_number', 'document_date' => 'receipt_date',
            'vendor_tax_id' => 'vendor.tax_id',
        ],
    ];

    /** @return array<string, string> UI/column key => schema path */
    public static function for(string $type): array
    {
        return self::COLUMNS[$type] ?? [];
    }

    /** schema path => column key (first match) */
    public static function columnFor(string $type, string $path): ?string
    {
        return array_search($path, self::for($type), true) ?: null;
    }
}
