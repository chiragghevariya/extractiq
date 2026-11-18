<?php

namespace App\Console\Commands;

use App\Services\Erp\ErpException;
use App\Services\Erp\OdooClient;
use Illuminate\Console\Command;

/**
 * Seeds an Odoo 17 database (the docker compose `odoo` service) with the demo ERP data from
 * samples/erp/seed.json: vendors (res.partner), products (product.product, by SKU) and the 10
 * scenario purchase orders PO-1001…PO-1010 (plus the PO-2001…2016 history with --all), confirmed; then validates their receipts (stock.picking) where
 * the Inventory app is installed. Safe to run again: everything is looked up before it's created.
 *
 *   php artisan extractiq:odoo-seed --install   # also installs Purchase, Inventory and Invoicing
 */
class SeedOdoo extends Command
{
    protected $signature = 'extractiq:odoo-seed {--file= : seed JSON (default ../samples/erp/seed.json)} {--install : install purchase, stock and account first} {--all : also create the history orders marked "bulk"}';

    protected $description = 'Create demo vendors, products and 10 purchase orders (with receipts) in Odoo over JSON-RPC';

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('../samples/erp/seed.json');
        if (! is_file($file)) {
            $this->error("No seed file at $file — run `node generate.mjs` in /samples.");

            return self::FAILURE;
        }
        $seed = json_decode((string) file_get_contents($file), true);
        $odoo = new OdooClient((array) config('extractiq.erp.drivers.odoo'));

        try {
            $version = $odoo->version();
            $odoo->login();
            $this->info('Odoo '.($version['server_version'] ?? '?').' · database '.$odoo->database());
            if ($this->option('install')) {
                $this->installModules($odoo);
            }
            $counts = $this->seed($odoo, $seed, (bool) $this->option('all'));
        } catch (ErpException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['', 'created', 'existing'], collect($counts)->map(fn ($c, $k) => [$k, $c['created'], $c['existing']])->values());

        return self::SUCCESS;
    }

    /** @return array<string, array{created: int, existing: int}> */
    public function seed(OdooClient $odoo, array $seed, bool $all = false): array
    {
        $counts = array_fill_keys(['vendors', 'products', 'purchase_orders', 'receipts'], ['created' => 0, 'existing' => 0]);
        $partners = [];
        foreach ($seed['vendors'] as $v) {
            $id = ($v['tax_id'] ? $odoo->find('res.partner', [['vat', '=', $v['tax_id']]]) : null) ?? $odoo->find('res.partner', [['name', '=', $v['name']]]);
            $counts['vendors'][$id ? 'existing' : 'created']++;
            $partners[$v['code']] = $id ?? (int) $odoo->execute('res.partner', 'create', [[
                'name' => $v['name'], 'vat' => $v['tax_id'], 'email' => $v['email'] ?? null, 'ref' => $v['code'], 'is_company' => true, 'supplier_rank' => 1,
            ]]);
        }

        $products = [];
        foreach ($seed['products'] as $p) {
            $id = $odoo->find('product.product', [['default_code', '=', $p['sku']]]);
            $counts['products'][$id ? 'existing' : 'created']++;
            $products[$p['sku']] = $id ?? (int) $odoo->execute('product.product', 'create', [[
                'name' => $p['name'], 'default_code' => $p['sku'], 'standard_price' => $p['cost'], 'detailed_type' => 'consu', 'purchase_ok' => true, 'sale_ok' => false,
            ]]);
        }

        $currencies = [];
        foreach ($seed['purchase_orders'] as $po) {
            if (($po['bulk'] ?? false) && ! $all) {
                continue;
            }
            if ($odoo->find('purchase.order', [['name', '=', $po['number']]])) {
                $counts['purchase_orders']['existing']++;

                continue;
            }
            $currencies[$po['currency']] ??= $this->currency($odoo, $po['currency']);
            $id = (int) $odoo->execute('purchase.order', 'create', [[
                'name' => $po['number'], 'partner_id' => $partners[$po['vendor_code']], 'date_order' => $po['order_date'].' 09:00:00',
                'currency_id' => $currencies[$po['currency']],
                'order_line' => array_map(fn ($l) => [0, 0, [
                    'product_id' => $products[$l['sku']] ?? null, 'name' => $l['description'], 'product_qty' => $l['quantity'],
                    'price_unit' => $l['unit_price'], 'date_planned' => $po['order_date'].' 09:00:00',
                ]], $po['lines']),
            ]]);
            $odoo->execute('purchase.order', 'button_confirm', [[$id]]);
            $counts['purchase_orders']['created']++;
        }

        foreach ($seed['receipts'] as $r) {
            try {
                $counts['receipts'][$this->receive($odoo, $r) ? 'created' : 'existing']++;
            } catch (ErpException $e) {
                if (str_contains($e->getMessage(), 'stock.')) {
                    $this->warn('Inventory app not installed: receipts skipped (run with --install).');
                    break;
                }
                throw $e;
            }
        }

        return $counts;
    }

    /** Validate the incoming picking of the receipt's PO for the received quantities; a backorder keeps the rest open. */
    private function receive(OdooClient $odoo, array $receipt): bool
    {
        $picking = $odoo->find('stock.picking', [['origin', '=', $receipt['po_number']], ['picking_type_code', '=', 'incoming'], ['state', 'not in', ['done', 'cancel']]]);
        if (! $picking) {
            return false; // already received (or no stockable lines)
        }
        $moves = $odoo->execute('stock.move', 'search_read', [[['picking_id', '=', $picking]]], ['fields' => ['id', 'product_id', 'product_uom_qty']]);
        $skus = collect($odoo->execute('product.product', 'read', [array_values(array_unique(array_map(fn ($m) => $m['product_id'][0], $moves)))], ['fields' => ['default_code']]))
            ->pluck('default_code', 'id');
        foreach ($moves as $m) {
            $line = collect($receipt['lines'])->firstWhere('sku', $skus[$m['product_id'][0]] ?? null);
            $odoo->execute('stock.move', 'write', [[$m['id']], ['quantity' => $line['quantity'] ?? 0, 'picked' => true]]);
        }
        $odoo->execute('stock.picking', 'button_validate', [[$picking]], ['context' => ['skip_backorder' => true, 'skip_sms' => true]]);

        return true;
    }

    private function currency(OdooClient $odoo, string $code): ?int
    {
        $id = $odoo->execute('res.currency', 'search', [[['name', '=', $code], ['active', 'in', [true, false]]]], ['limit' => 1])[0] ?? null;
        if ($id) {
            $odoo->execute('res.currency', 'write', [[$id], ['active' => true]]);
        }

        return $id;
    }

    private function installModules(OdooClient $odoo): void
    {
        foreach (['account', 'purchase', 'stock'] as $module) {
            $row = $odoo->execute('ir.module.module', 'search_read', [[['name', '=', $module]]], ['fields' => ['id', 'state']])[0] ?? null;
            if ($row && $row['state'] !== 'installed') {
                $this->line("Installing {$module}…");
                $odoo->execute('ir.module.module', 'button_immediate_install', [[$row['id']]]);
            }
        }
    }
}
