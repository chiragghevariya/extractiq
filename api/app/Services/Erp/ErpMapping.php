<?php

namespace App\Services\Erp;

use App\Models\ErpConnection;

/**
 * How a bill is written into one ERP, editable per connector in the UI:
 *  - fields: our header field -> the ERP field it goes to (or "" to leave it out);
 *  - default_account: the GL account for expense lines;
 *  - tax_codes: our tax rate (%) -> the ERP's tax code;
 *  - cost_centres: per vendor, a cost centre (Odoo analytic account, QuickBooks class, …) and
 *    optionally a GL account that overrides the default for that vendor.
 */
final class ErpMapping
{
    /** Our header fields that can be mapped, with a label for the UI. */
    public const SOURCE_FIELDS = [
        'document_number' => 'Invoice number',
        'po_number' => 'PO number',
        'document_date' => 'Invoice date',
        'due_date' => 'Due date',
        'payment_terms' => 'Payment terms',
        'document_uuid' => 'ExtractIQ id',
    ];

    /** Per connector: the ERP fields each of ours may go to; the first is the default. null = free text (webhook). */
    public const TARGETS = [
        'odoo' => [
            'document_number' => ['ref', 'payment_reference'],
            'po_number' => ['invoice_origin', 'narration'],
            'document_date' => ['invoice_date'],
            'due_date' => ['invoice_date_due'],
            'payment_terms' => ['narration', ''],
            'document_uuid' => ['narration', ''],
        ],
        'quickbooks' => [
            'document_number' => ['DocNumber'],
            'po_number' => ['PrivateNote', ''],
            'document_date' => ['TxnDate'],
            'due_date' => ['DueDate'],
            'payment_terms' => ['PrivateNote', ''],
            'document_uuid' => ['PrivateNote', ''],
        ],
        'mock' => [
            'document_number' => ['bill_number', 'reference'],
            'po_number' => ['po_number', 'memo'],
            'document_date' => ['bill_date'],
            'due_date' => ['due_date'],
            'payment_terms' => ['terms', 'memo', ''],
            'document_uuid' => ['external_id', 'memo', ''],
        ],
        'webhook' => null,
    ];

    public const DEFAULT_ACCOUNTS = ['odoo' => '600000', 'quickbooks' => '7', 'mock' => '5000', 'webhook' => null];

    /**
     * @param  array<string, string>  $fields
     * @param  list<array{rate: float, code: string}>  $taxCodes
     * @param  list<array{vendor_id: int, cost_centre: ?string, account: ?string}>  $costCentres
     */
    public function __construct(
        public readonly string $connector,
        public readonly array $fields,
        public readonly ?string $defaultAccount,
        public readonly array $taxCodes,
        public readonly array $costCentres,
    ) {}

    public static function defaults(string $connector): array
    {
        $targets = self::TARGETS[$connector] ?? null;
        $fields = [];
        foreach (array_keys(self::SOURCE_FIELDS) as $f) {
            $fields[$f] = $targets === null ? $f : ($targets[$f][0] ?? '');
        }
        $account = $connector === 'quickbooks' ? (string) config('extractiq.erp.drivers.quickbooks.expense_account_id', '7')
            : ($connector === 'odoo' ? (string) config('extractiq.erp.drivers.odoo.expense_account_code', '600000') : self::DEFAULT_ACCOUNTS[$connector] ?? null);

        return ['fields' => $fields, 'default_account' => $account, 'tax_codes' => [], 'cost_centres' => []];
    }

    public static function for(ErpConnection $connection): self
    {
        $m = array_replace(self::defaults($connection->connector), $connection->mapping ?? []);
        $m['fields'] = array_replace(self::defaults($connection->connector)['fields'], $m['fields'] ?? []);

        return new self($connection->connector, $m['fields'], $m['default_account'] ?: null,
            array_values($m['tax_codes'] ?? []), array_values($m['cost_centres'] ?? []));
    }

    public function toArray(): array
    {
        return ['fields' => $this->fields, 'default_account' => $this->defaultAccount, 'tax_codes' => $this->taxCodes, 'cost_centres' => $this->costCentres];
    }

    /** The ERP field ours maps to, or null when it's left out. */
    public function target(string $field): ?string
    {
        return ($this->fields[$field] ?? '') !== '' ? $this->fields[$field] : null;
    }

    public function accountFor(?int $vendorId): ?string
    {
        $rule = $this->ruleFor($vendorId);

        return ($rule['account'] ?? null) ?: $this->defaultAccount;
    }

    public function costCentreFor(?int $vendorId): ?string
    {
        return ($this->ruleFor($vendorId)['cost_centre'] ?? null) ?: null;
    }

    /** Tax code for a rate (%), matching to 0.01; null when unmapped. */
    public function taxCodeFor(?float $rate): ?string
    {
        if ($rate === null) {
            return null;
        }
        foreach ($this->taxCodes as $t) {
            if (abs((float) $t['rate'] - $rate) < 0.01) {
                return $t['code'];
            }
        }

        return null;
    }

    private function ruleFor(?int $vendorId): ?array
    {
        if (! $vendorId) {
            return null;
        }
        foreach ($this->costCentres as $r) {
            if ((int) $r['vendor_id'] === $vendorId) {
                return $r;
            }
        }

        return null;
    }
}
