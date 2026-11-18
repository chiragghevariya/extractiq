<?php

namespace App\Services\Erp\Drivers;

use App\Services\Erp\BillStatus;
use App\Services\Erp\ErpBill;
use App\Services\Erp\ErpConnector;
use App\Services\Erp\ErpException;
use App\Services\Erp\OdooClient;
use App\Services\Erp\PushResult;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Odoo 17 over JSON-RPC (/jsonrpc, services "common" and "object").
 *
 * Bills: account.move (move_type in_invoice) for the res.partner of the vendor, each line with
 * its product (by SKU = default_code), GL account (by code), purchase taxes (by name, from the tax
 * mapping) and analytic distribution (cost centre), and linked to the purchase.order.line it bills
 * (purchase_line_id), which updates the PO's billed quantities. The original PDF is attached as an
 * ir.attachment and set as the bill's main attachment. Idempotent: a bill with the same partner
 * and reference is returned instead of created.
 *
 * Sync: res.partner (suppliers), confirmed purchase.order + lines, incoming stock.picking (or, without
 * the Inventory app, the PO lines' qty_received), and bill state/payment_state.
 */
class OdooConnector implements ErpConnector
{
    private readonly OdooClient $client;

    /** @var array<string, mixed> per-push lookup cache */
    private array $cache = [];

    public function __construct(array $config, ?OdooClient $client = null)
    {
        $this->client = $client ?? new OdooClient($config);
    }

    public function name(): string
    {
        return 'odoo';
    }

    public function capabilities(): array
    {
        return ['sync_vendors' => true, 'sync_purchase_orders' => true, 'sync_receipts' => true, 'bill_status' => true, 'attachments' => true];
    }

    public function testConnection(): array
    {
        try {
            $version = $this->client->version();
            $uid = $this->client->login();
            $this->execute('account.move', 'check_access_rights', ['create'], ['raise_exception' => true]);

            return ['ok' => true, 'message' => sprintf('Connected to Odoo %s, database "%s", as uid %d.', data_get($version, 'server_version', '?'), $this->client->database(), $uid)];
        } catch (ErpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncVendors(): array
    {
        $rows = $this->execute('res.partner', 'search_read', [[['supplier_rank', '>', 0], ['active', '=', true]]], ['fields' => ['id', 'name', 'vat', 'email']]);

        return array_map(fn ($p) => [
            'name' => $p['name'], 'tax_id' => $p['vat'] ?: null, 'email' => $p['email'] ?: null, 'erp_ref' => "odoo:{$p['id']}",
        ], $rows);
    }

    public function syncPurchaseOrders(): array
    {
        $orders = $this->execute('purchase.order', 'search_read', [[['state', 'in', ['purchase', 'done']]]],
            ['fields' => ['id', 'name', 'partner_id', 'date_order', 'date_planned', 'currency_id', 'state']]);
        if (! $orders) {
            return [];
        }
        $lines = collect($this->execute('purchase.order.line', 'search_read', [[['order_id', 'in', array_column($orders, 'id')], ['display_type', '=', false]]],
            ['fields' => ['id', 'order_id', 'sequence', 'product_id', 'name', 'product_qty', 'price_unit', 'product_uom']]))->groupBy(fn ($l) => $l['order_id'][0]);
        $skus = $this->skus($lines->flatten(1)->pluck('product_id')->filter()->map(fn ($p) => $p[0])->unique()->values()->all());

        return array_map(function ($o) use ($lines, $skus) {
            $currency = is_array($o['currency_id']) ? $o['currency_id'][1] : null;
            $poLines = $lines->get($o['id'], collect())->sortBy(fn ($l) => [$l['sequence'], $l['id']])->values();

            return [
                'header' => [
                    'po_number' => $o['name'], 'vendor_erp_ref' => "odoo:{$o['partner_id'][0]}", 'vendor_name' => $o['partner_id'][1],
                    'order_date' => substr((string) $o['date_order'], 0, 10), 'expected_date' => $o['date_planned'] ? substr((string) $o['date_planned'], 0, 10) : null,
                    'currency' => $currency, 'status' => $o['state'] === 'done' ? 'closed' : 'open', 'erp_ref' => "odoo:po:{$o['id']}",
                ],
                'lines' => $poLines->map(fn ($l, $i) => [
                    'line_no' => $i + 1,
                    'sku' => $l['product_id'] ? ($skus[$l['product_id'][0]] ?? null) : null,
                    'description' => (string) $l['name'],
                    'quantity' => (float) $l['product_qty'],
                    'uom' => is_array($l['product_uom']) ? $l['product_uom'][1] : null,
                    'unit_price_minor' => Money::toMinor($l['price_unit'], $currency),
                    'erp_ref' => "odoo:pol:{$l['id']}",
                ])->all(),
            ];
        }, $orders);
    }

    public function syncReceipts(): array
    {
        try {
            $pickings = $this->execute('stock.picking', 'search_read', [[['picking_type_code', '=', 'incoming'], ['state', '=', 'done']]],
                ['fields' => ['id', 'name', 'origin', 'date_done']]);
        } catch (ErpException $e) {
            if (! str_contains($e->getMessage(), 'stock.picking')) {
                throw $e;
            }

            return $this->receiptsFromPurchaseLines(); // Inventory app not installed
        }
        if (! $pickings) {
            return [];
        }
        $moves = collect($this->execute('stock.move', 'search_read', [[['picking_id', 'in', array_column($pickings, 'id')], ['state', '=', 'done']]],
            ['fields' => ['picking_id', 'product_id', 'name', 'quantity', 'purchase_line_id']]))->groupBy(fn ($m) => $m['picking_id'][0]);
        $skus = $this->skus($moves->flatten(1)->pluck('product_id')->filter()->map(fn ($p) => $p[0])->unique()->values()->all());

        return array_values(array_filter(array_map(fn ($p) => $p['origin'] ? [
            'header' => ['grn_number' => $p['name'], 'po_number' => $p['origin'], 'received_date' => $p['date_done'] ? substr((string) $p['date_done'], 0, 10) : null, 'erp_ref' => "odoo:picking:{$p['id']}"],
            'lines' => $moves->get($p['id'], collect())->values()->map(fn ($m, $i) => [
                'line_no' => $i + 1, 'po_line_erp_ref' => $m['purchase_line_id'] ? "odoo:pol:{$m['purchase_line_id'][0]}" : null,
                'sku' => $m['product_id'] ? ($skus[$m['product_id'][0]] ?? null) : null, 'description' => (string) $m['name'], 'quantity' => (float) $m['quantity'],
            ])->all(),
        ] : null, $pickings)));
    }

    public function pushVendorBill(ErpBill $bill): PushResult
    {
        $this->cache = [];
        $partnerId = $this->partner($bill);
        $ref = $bill->fields['ref'] ?? $bill->fields['payment_reference'] ?? $bill->number;

        $existing = $this->execute('account.move', 'search', [[['move_type', '=', 'in_invoice'], ['partner_id', '=', $partnerId], ['ref', '=', $ref]]], ['limit' => 1]);
        if ($existing) {
            return new PushResult("odoo:account.move:{$existing[0]}", ['id' => $existing[0]], alreadyExisted: true, url: $this->url($existing[0]),
                request: ['search' => ['partner_id' => $partnerId, 'ref' => $ref]], httpStatus: 200);
        }

        $poLines = $this->purchaseLines($bill);
        $values = array_filter([
            'move_type' => 'in_invoice',
            'partner_id' => $partnerId,
            'currency_id' => $this->lookup('res.currency', [['name', '=', $bill->currency]]),
            'invoice_date' => $bill->date,
        ]) + array_intersect_key($bill->fields, array_flip(['ref', 'payment_reference', 'invoice_origin', 'invoice_date', 'invoice_date_due', 'narration']));
        $values['invoice_line_ids'] = array_map(function ($l) use ($poLines) {
            $productId = $l['sku'] ? $this->lookup('product.product', [['default_code', '=', $l['sku']]]) : null;
            $poLineId = $this->poLineFor($l, $productId, $poLines);
            $analytic = $l['cost_centre'] ? $this->lookup('account.analytic.account', ['|', ['code', '=', $l['cost_centre']], ['name', '=', $l['cost_centre']]]) : null;

            return [0, 0, array_filter([
                'product_id' => $productId,
                'name' => trim(($l['sku'] ? "[{$l['sku']}] " : '').$l['description']),
                'quantity' => $l['quantity'],
                'price_unit' => $l['unit_price'],
                'account_id' => $l['account'] ? $this->lookup('account.account', [['code', '=', $l['account']]]) : null,
                'tax_ids' => [[6, 0, $l['tax_code'] ? array_filter([$this->lookup('account.tax', [['name', '=', $l['tax_code']], ['type_tax_use', '=', 'purchase']])]) : []]],
                'purchase_line_id' => $poLineId,
                'analytic_distribution' => $analytic ? [(string) $analytic => 100] : null,
            ], fn ($v) => $v !== null)];
        }, $bill->lines);

        $id = (int) $this->execute('account.move', 'create', [$values]);
        $attachmentId = null;
        if ($contents = $bill->attachmentContents()) {
            $attachmentId = (int) $this->execute('ir.attachment', 'create', [[
                'name' => $bill->attachment['filename'], 'type' => 'binary', 'datas' => base64_encode($contents),
                'res_model' => 'account.move', 'res_id' => $id, 'mimetype' => $bill->attachment['mime'],
            ]]);
            $this->execute('account.move', 'write', [[$id], ['message_main_attachment_id' => $attachmentId]]);
        }

        return new PushResult("odoo:account.move:$id", ['id' => $id, 'attachment_id' => $attachmentId, 'purchase_lines_linked' => count(array_filter(array_column(array_column($values['invoice_line_ids'], 2), 'purchase_line_id')))],
            url: $this->url($id), request: ['model' => 'account.move', 'method' => 'create', 'values' => $values], httpStatus: 200);
    }

    public function getBillStatus(string $ref): ?BillStatus
    {
        if (! preg_match('/^odoo:account\.move:(\d+)$/', $ref, $m)) {
            return null;
        }
        $row = $this->execute('account.move', 'read', [[(int) $m[1]]], ['fields' => ['state', 'payment_state', 'write_date']])[0] ?? null;
        if (! $row) {
            return null;
        }
        $state = match (true) {
            $row['state'] === 'cancel' => 'cancelled',
            $row['state'] === 'draft' => 'draft',
            in_array($row['payment_state'], ['paid', 'in_payment', 'reversed'], true) => 'paid',
            default => 'posted',
        };

        return new BillStatus($state, $state === 'paid' && $row['write_date'] ? Carbon::parse($row['write_date']) : null, "{$row['state']}/{$row['payment_state']}");
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function partner(ErpBill $bill): int
    {
        if ($bill->vendorRef && preg_match('/^odoo:(\d+)$/', $bill->vendorRef, $m)) {
            return (int) $m[1];
        }
        $id = ($bill->vendorTaxId ? $this->lookup('res.partner', [['vat', '=', $bill->vendorTaxId]]) : null)
            ?? $this->lookup('res.partner', [['name', '=ilike', $bill->vendorName]]);

        return $id ?? (int) $this->execute('res.partner', 'create', [[
            'name' => $bill->vendorName, 'vat' => $bill->vendorTaxId, 'supplier_rank' => 1, 'is_company' => true,
        ]]);
    }

    /** The PO's lines keyed by id, with their product, so bill lines can be linked. @return list<array> */
    private function purchaseLines(ErpBill $bill): array
    {
        $poId = $bill->poRef && preg_match('/^odoo:po:(\d+)$/', $bill->poRef, $m) ? (int) $m[1]
            : ($bill->poNumber ? $this->lookup('purchase.order', [['name', '=', $bill->poNumber]]) : null);
        if (! $poId) {
            return [];
        }

        return $this->execute('purchase.order.line', 'search_read', [[['order_id', '=', $poId], ['display_type', '=', false]]], ['fields' => ['id', 'product_id', 'name']]);
    }

    private function poLineFor(array $line, ?int $productId, array $poLines): ?int
    {
        if ($line['po_line_ref'] && preg_match('/^odoo:pol:(\d+)$/', $line['po_line_ref'], $m)) {
            return (int) $m[1];
        }
        foreach ($poLines as $pl) {
            if ($productId && is_array($pl['product_id']) && $pl['product_id'][0] === $productId) {
                return $pl['id'];
            }
        }

        return null;
    }

    /** @param list<int> $productIds @return array<int, ?string> product id => default_code */
    private function skus(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return collect($this->execute('product.product', 'read', [$productIds], ['fields' => ['default_code']]))
            ->mapWithKeys(fn ($p) => [$p['id'] => $p['default_code'] ?: null])->all();
    }

    private function lookup(string $model, array $domain): ?int
    {
        $key = $model.json_encode($domain);
        if (! array_key_exists($key, $this->cache)) {
            $ids = $this->execute($model, 'search', [$domain], ['limit' => 1]);
            $this->cache[$key] = $ids ? (int) $ids[0] : null;
        }

        return $this->cache[$key];
    }

    private function url(int $id): string
    {
        return $this->client->url("/web#id=$id&model=account.move&view_type=form");
    }

    private function execute(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        return $this->client->execute($model, $method, $args, $kwargs);
    }

    private function receiptsFromPurchaseLines(): array
    {
        $lines = $this->execute('purchase.order.line', 'search_read', [[['qty_received', '>', 0], ['display_type', '=', false]]],
            ['fields' => ['id', 'order_id', 'name', 'qty_received', 'product_id']]);

        return collect($lines)->groupBy(fn ($l) => $l['order_id'][1])->map(fn ($ls, $po) => [
            'header' => ['grn_number' => "$po/RCV", 'po_number' => $po, 'received_date' => null, 'erp_ref' => "odoo:received:$po"],
            'lines' => $ls->values()->map(fn ($l, $i) => [
                'line_no' => $i + 1, 'po_line_erp_ref' => "odoo:pol:{$l['id']}", 'description' => (string) $l['name'], 'quantity' => (float) $l['qty_received'],
            ])->all(),
        ])->values()->all();
    }
}
