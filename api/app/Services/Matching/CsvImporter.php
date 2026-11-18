<?php

namespace App\Services\Matching;

use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\Money;
use Carbon\Carbon;
use Throwable;

/**
 * CSV import for vendors, purchase orders and goods receipts. Header names are matched
 * case-insensitively; PO and GRN files have one row per line, grouped by number. Valid
 * records are imported and every bad row is reported with its row number — a typo on
 * row 40 doesn't throw away rows 1–39.
 */
class CsvImporter
{
    public const KINDS = ['vendors', 'purchase_orders', 'goods_receipts'];

    public const TEMPLATES = [
        'vendors' => ['name', 'tax_id', 'aliases', 'email', 'default_currency', 'erp_ref'],
        'purchase_orders' => ['po_number', 'vendor', 'order_date', 'expected_date', 'currency', 'status', 'line_no', 'sku', 'description', 'quantity', 'uom', 'unit_price'],
        'goods_receipts' => ['grn_number', 'po_number', 'received_date', 'line_no', 'po_line_no', 'sku', 'description', 'quantity', 'uom'],
    ];

    public function __construct(private readonly MasterData $master) {}

    /** @return array{created: int, updated: int, rows: int, errors: list<array{row: int, message: string}>, affected_po_ids: list<int>} */
    public function import(int $companyId, string $kind, string $csv, string $source = 'csv'): array
    {
        if (! isset(self::TEMPLATES[$kind])) {
            throw new \InvalidArgumentException("Unknown import kind: $kind");
        }
        [$rows, $errors] = $this->parse($csv, self::TEMPLATES[$kind]);
        $result = ['created' => 0, 'updated' => 0, 'rows' => count($rows), 'errors' => $errors, 'affected_po_ids' => []];
        if ($errors && ! $rows) {
            return $result;
        }

        match ($kind) {
            'vendors' => $this->vendors($companyId, $rows, $source, $result),
            'purchase_orders' => $this->purchaseOrders($companyId, $rows, $source, $result),
            'goods_receipts' => $this->goodsReceipts($companyId, $rows, $source, $result),
        };
        usort($result['errors'], fn ($a, $b) => $a['row'] <=> $b['row']);
        $result['affected_po_ids'] = array_values(array_unique($result['affected_po_ids']));

        return $result;
    }

    private function vendors(int $companyId, array $rows, string $source, array &$result): void
    {
        foreach ($rows as [$n, $r]) {
            if (($r['name'] ?? '') === '') {
                $result['errors'][] = ['row' => $n, 'message' => 'name is required.'];

                continue;
            }
            if (($cur = $r['default_currency'] ?? '') !== '' && ! preg_match('/^[A-Za-z]{3}$/', $cur)) {
                $result['errors'][] = ['row' => $n, 'message' => "default_currency \"$cur\" is not a 3-letter code."];

                continue;
            }
            $v = $this->master->upsertVendor($companyId, [
                'name' => $r['name'], 'tax_id' => $r['tax_id'] ?? null, 'email' => $r['email'] ?? null, 'default_currency' => $cur ?: null,
                'erp_ref' => $r['erp_ref'] ?? null, 'aliases' => $this->list($r['aliases'] ?? ''),
            ], $source);
            $result[$v->wasRecentlyCreated ? 'created' : 'updated']++;
        }
    }

    private function purchaseOrders(int $companyId, array $rows, string $source, array &$result): void
    {
        foreach ($this->group($rows, 'po_number', $result) as $number => $group) {
            [$firstRow, $h] = $group[0];
            $vendor = $this->vendor($companyId, $h['vendor'] ?? '');
            if (! $vendor) {
                $result['errors'][] = ['row' => $firstRow, 'message' => 'Vendor "'.($h['vendor'] ?? '').'" not found — import vendors first (name, alias, tax ID or ERP ref).'];

                continue;
            }
            $currency = strtoupper(($h['currency'] ?? '') ?: $vendor->default_currency);
            $lines = $this->lines($group, $currency, true, $result);
            if ($lines === null) {
                continue;
            }
            $existed = PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($number))->exists();
            $status = strtolower($h['status'] ?? '') ?: 'open';
            $po = $this->master->upsertPurchaseOrder($companyId, [
                'po_number' => $number, 'vendor_id' => $vendor->id, 'order_date' => $this->date($h['order_date'] ?? null),
                'expected_date' => $this->date($h['expected_date'] ?? null), 'currency' => $currency,
                'status' => in_array($status, ['open', 'closed', 'cancelled'], true) ? $status : 'open',
            ], $lines, $source);
            $result[$existed ? 'updated' : 'created']++;
            $result['affected_po_ids'][] = $po->id;
        }
    }

    private function goodsReceipts(int $companyId, array $rows, string $source, array &$result): void
    {
        foreach ($this->group($rows, 'grn_number', $result) as $number => $group) {
            [$firstRow, $h] = $group[0];
            $poNumber = $h['po_number'] ?? '';
            if ($poNumber === '' || ! PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($poNumber))->exists()) {
                $result['errors'][] = ['row' => $firstRow, 'message' => "Purchase order \"$poNumber\" not found — import it before its receipts."];

                continue;
            }
            $lines = $this->lines($group, null, false, $result);
            if ($lines === null) {
                continue;
            }
            $existed = \App\Models\GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('number_key', PurchaseOrder::key($number))->exists();
            $grn = $this->master->upsertGoodsReceipt($companyId, [
                'grn_number' => $number, 'po_number' => $poNumber, 'received_date' => $this->date($h['received_date'] ?? null),
            ], $lines, $source);
            $result[$existed ? 'updated' : 'created']++;
            if ($grn->purchase_order_id) {
                $result['affected_po_ids'][] = $grn->purchase_order_id;
            }
        }
    }

    /** @return ?list<array<string, mixed>> null when any line in the group is invalid (the whole record is skipped) */
    private function lines(array $group, ?string $currency, bool $priced, array &$result): ?array
    {
        $out = [];
        $bad = false;
        foreach ($group as [$n, $r]) {
            $qty = str_replace(',', '', $r['quantity'] ?? '');
            if (($r['description'] ?? '') === '' && ($r['sku'] ?? '') === '') {
                $result['errors'][] = ['row' => $n, 'message' => 'description or sku is required.'];
                $bad = true;
            } elseif (! is_numeric($qty) || (float) $qty <= 0) {
                $result['errors'][] = ['row' => $n, 'message' => 'quantity must be a positive number.'];
                $bad = true;
            } elseif ($priced && ($r['unit_price'] ?? '') !== '' && Money::toMinor($r['unit_price'], $currency) === null) {
                $result['errors'][] = ['row' => $n, 'message' => "unit_price \"{$r['unit_price']}\" is not a number."];
                $bad = true;
            }
            if ($bad) {
                continue;
            }
            $out[] = array_filter([
                'line_no' => is_numeric($r['line_no'] ?? null) ? (int) $r['line_no'] : null,
                'po_line_no' => is_numeric($r['po_line_no'] ?? null) ? (int) $r['po_line_no'] : null,
                'sku' => ($r['sku'] ?? '') ?: null,
                'description' => ($r['description'] ?? '') ?: $r['sku'],
                'quantity' => (float) $qty,
                'uom' => ($r['uom'] ?? '') ?: null,
                'unit_price_minor' => $priced && ($r['unit_price'] ?? '') !== '' ? Money::toMinor($r['unit_price'], $currency) : null,
            ], fn ($v) => $v !== null);
        }

        return $bad ? null : $out;
    }

    /** @return array<string, list<array{0: int, 1: array<string, string>}>> */
    private function group(array $rows, string $key, array &$result): array
    {
        $groups = [];
        foreach ($rows as [$n, $r]) {
            if (($r[$key] ?? '') === '') {
                $result['errors'][] = ['row' => $n, 'message' => "$key is required."];

                continue;
            }
            $groups[PurchaseOrder::key($r[$key])] ??= ['number' => $r[$key], 'rows' => []];
            $groups[PurchaseOrder::key($r[$key])]['rows'][] = [$n, $r];
        }

        return collect($groups)->mapWithKeys(fn ($g) => [$g['number'] => $g['rows']])->all();
    }

    private function vendor(int $companyId, string $ref): ?Vendor
    {
        if ($ref === '') {
            return null;
        }
        $q = fn () => Vendor::withoutGlobalScopes()->where('company_id', $companyId);

        return $q()->where('erp_ref', $ref)->first()
            ?? (($tax = Vendor::taxKey($ref)) ? $q()->where('tax_key', $tax)->first() : null)
            ?? $q()->where('normalized_name', Vendor::normalize($ref))->first()
            ?? $q()->whereNotNull('alias_keys')->get()->first(fn (Vendor $v) => in_array(Vendor::normalize($ref), $v->alias_keys ?? [], true));
    }

    /** @return array{0: list<array{0: int, 1: array<string, string>}>, 1: list<array{row: int, message: string}>} */
    private function parse(string $csv, array $columns): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // Excel BOM
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, (string) $csv);
        rewind($handle);
        $header = fgetcsv($handle, escape: '\\');
        if (! $header) {
            return [[], [['row' => 1, 'message' => 'The file is empty.']]];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $key = $columns[0];
        if (! in_array($key, $header, true)) {
            return [[], [['row' => 1, 'message' => "Missing the \"$key\" column. Expected: ".implode(', ', $columns).'.']]];
        }
        $rows = [];
        $n = 1;
        while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
            $n++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = [$n, $row];
        }
        fclose($handle);

        return [$rows, []];
    }

    /** @return list<string> */
    private function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;|]/', $value) ?: [])));
    }

    private function date(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
