<?php

use App\Console\Commands\SeedOdoo;
use App\Models\ErpConnection;
use App\Models\MockErp\Bill as MockBill;
use App\Models\Vendor;
use App\Services\Erp\Drivers\WebhookConnector;
use App\Services\Erp\ErpContext;
use App\Services\Erp\ErpException;
use App\Services\Erp\ErpManager;
use App\Services\Erp\MockErp;
use App\Services\Erp\OdooClient;
use App\Services\Erp\QuickBooksOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->company = demoCompany();
    $this->vendor = Vendor::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Kestrel Industrial Parts LLC', 'tax_id' => '88-1234567']);
    $this->doc = pushableInvoice($this->company, ['vendor_id' => $this->vendor->id, 'vendor_name' => 'Kestrel Industrial Parts LLC']);
    $this->connector = fn (string $name) => app(ErpManager::class)->forCompany($this->company->id, $name);
});

it('builds every connector for a company and lists what each can do', function () {
    foreach (['mock', 'odoo', 'quickbooks', 'webhook'] as $name) {
        expect(($this->connector)($name)->name())->toBe($name);
    }
    expect(($this->connector)('quickbooks')->capabilities()['sync_receipts'])->toBeFalse()
        ->and(($this->connector)('webhook')->capabilities()['bill_status'])->toBeFalse()
        ->and(($this->connector)('odoo')->capabilities())->each->toBeTrue();
});

describe('mock ERP', function () {
    beforeEach(function () {
        app(MockErp::class)->seed($this->company->id, json_decode(file_get_contents(base_path('../samples/erp/seed.json')), true));
    });

    it('syncs its vendors, purchase orders (with line refs) and receipts', function () {
        $c = ($this->connector)('mock');
        $pos = collect($c->syncPurchaseOrders());

        expect($c->syncVendors())->toHaveCount(4)->and($c->syncVendors()[0]['erp_ref'])->toBe('mock:V-100')
            ->and($pos)->toHaveCount(26)->and($pos->firstWhere('header.po_number', 'PO-1008')['lines'][1]['erp_ref'])->toBe('mock:PO-1008:2')
            ->and(collect($c->syncReceipts())->firstWhere('header.grn_number', 'GRN-5008')['lines'][1]['po_line_erp_ref'])->toBe('mock:PO-1008:2');
    });

    it('creates a bill with mapped accounts, keeps the PDF, and is idempotent per document uuid', function () {
        ErpConnection::for($this->company->id, 'mock')->update(['mapping' => [
            'tax_codes' => [['rate' => 8, 'code' => 'PURCH-8']], 'cost_centres' => [['vendor_id' => $this->vendor->id, 'cost_centre' => 'CC-PLANT', 'account' => '5100']],
        ]]);
        $c = ($this->connector)('mock');
        $first = $c->pushVendorBill(billFor($this->doc, 'mock'));
        $again = $c->pushVendorBill(billFor($this->doc, 'mock'));

        $bill = MockBill::withoutGlobalScopes()->first();
        expect($first->externalRef)->toMatch('/^BILL-\d{5}$/')->and($again->alreadyExisted)->toBeTrue()->and($again->externalRef)->toBe($first->externalRef)
            ->and(MockBill::withoutGlobalScopes()->count())->toBe(1)
            ->and($bill->idempotency_key)->toBe($this->doc->uuid)->and($bill->vendor_code)->toBe('V-100')
            ->and($bill->lines[0])->toMatchArray(['account' => '5100', 'tax_code' => 'PURCH-8', 'cost_centre' => 'CC-PLANT'])
            ->and($bill->attachment_name)->toBe('inv-77.pdf')->and(Storage::disk('local')->get($bill->attachment_path))->toBe('%PDF-1.4 fake')
            ->and($first->url)->toContain('/mock-erp?bill=');
    });

    it('reports bill status as AP posts and pays it', function () {
        $c = ($this->connector)('mock');
        $ref = $c->pushVendorBill(billFor($this->doc, 'mock'))->externalRef;
        $bill = MockBill::withoutGlobalScopes()->where('ref', $ref)->first();

        expect($c->getBillStatus($ref)->state)->toBe('draft');
        app(MockErp::class)->post($bill);
        expect($c->getBillStatus($ref)->state)->toBe('posted');
        app(MockErp::class)->pay($bill->fresh());
        expect($c->getBillStatus($ref)->state)->toBe('paid')->and($c->getBillStatus($ref)->paidAt)->not->toBeNull();
    });

    it('simulates an outage (retryable 503) and rejects bad data (not retryable)', function () {
        ErpConnection::for($this->company->id, 'mock')->update(['settings' => ['outage' => true]]);
        try {
            ($this->connector)('mock')->pushVendorBill(billFor($this->doc, 'mock'));
            $this->fail('expected an outage');
        } catch (ErpException $e) {
            expect($e->retryable)->toBeTrue()->and($e->httpStatus)->toBe(503);
        }
        expect(($this->connector)('mock')->testConnection()['ok'])->toBeFalse();

        ErpConnection::for($this->company->id, 'mock')->update(['settings' => ['outage' => false]]);
        $this->doc->update(['total_minor' => 0]);
        expect(fn () => ($this->connector)('mock')->pushVendorBill(billFor($this->doc, 'mock')))
            ->toThrow(fn (ErpException $e) => expect($e->retryable)->toBeFalse()->and($e->httpStatus)->toBe(422));
    });

    it('records a delivery of what is still open on a PO', function () {
        $po = App\Models\MockErp\PurchaseOrder::withoutGlobalScopes()->where('number', 'PO-1008')->first();
        $r = app(MockErp::class)->receiveRemaining($po);

        expect($r->lines)->toHaveCount(2)->and(collect($r->lines)->pluck('quantity')->all())->toEqual([4, 100]); // hose 10-6, gloves 100
        expect(fn () => app(MockErp::class)->receiveRemaining($po))->toThrow(Illuminate\Validation\ValidationException::class);
    });
});

describe('odoo', function () {
    beforeEach(function () {
        config(['extractiq.erp.drivers.odoo' => ['url' => 'http://odoo.test', 'database' => 'odoo', 'username' => 'admin', 'password' => 'pw', 'expense_account_code' => '600000']]);
        ErpConnection::for($this->company->id, 'odoo')->update(['mapping' => [
            'tax_codes' => [['rate' => 8, 'code' => '8% Purchase']], 'cost_centres' => [['vendor_id' => $this->vendor->id, 'cost_centre' => 'Plant 1', 'account' => null]],
        ]]);
    });

    it('creates an in_invoice bill linked to the purchase order, with products, accounts, taxes, analytics and the PDF', function () {
        $calls = fakeOdoo([
            'res.partner.search' => [55],
            'account.move.search' => [],
            'res.currency.search' => [2],
            'purchase.order.search' => [9],
            'purchase.order.line.search_read' => [['id' => 501, 'product_id' => [11, 'Hex bolt'], 'name' => 'Hex bolt'], ['id' => 502, 'product_id' => [12, 'Nut'], 'name' => 'Nut']],
            'product.product.search' => fn ($args) => $args[0][0][2] === 'A-1' ? [11] : [12],
            'account.account.search' => [600],
            'account.tax.search' => [21],
            'account.analytic.account.search' => [3],
            'account.move.create' => 901,
            'ir.attachment.create' => 77,
            'account.move.write' => true,
        ]);

        $r = ($this->connector)('odoo')->pushVendorBill(billFor($this->doc, 'odoo'));

        $create = odooCalls($calls, 'account.move.create')[0]['args'][0];
        $line = $create['invoice_line_ids'][0][2];
        expect($r->externalRef)->toBe('odoo:account.move:901')->and($r->url)->toBe('http://odoo.test/web#id=901&model=account.move&view_type=form')
            ->and($create)->toMatchArray(['move_type' => 'in_invoice', 'partner_id' => 55, 'currency_id' => 2, 'ref' => 'INV-77', 'invoice_origin' => 'PO-9', 'invoice_date_due' => '2026-12-01'])
            ->and($line)->toMatchArray(['product_id' => 11, 'account_id' => 600, 'tax_ids' => [[6, 0, [21]]], 'purchase_line_id' => 501, 'analytic_distribution' => ['3' => 100], 'quantity' => 10.0, 'price_unit' => 10.0])
            ->and($create['invoice_line_ids'][1][2]['purchase_line_id'])->toBe(502);
        // vendor -> res.partner by VAT first
        expect(odooCalls($calls, 'res.partner.search')[0]['args'][0])->toBe([['vat', '=', '88-1234567']]);
        // the original PDF attached and set as the main attachment
        $att = odooCalls($calls, 'ir.attachment.create')[0]['args'][0];
        expect($att)->toMatchArray(['res_model' => 'account.move', 'res_id' => 901, 'name' => 'inv-77.pdf', 'datas' => base64_encode('%PDF-1.4 fake')])
            ->and(odooCalls($calls, 'account.move.write')[0]['args'])->toBe([[901], ['message_main_attachment_id' => 77]]);
    });

    it('returns the existing bill instead of creating a duplicate', function () {
        $calls = fakeOdoo(['res.partner.search' => [55], 'account.move.search' => [900]]);
        $r = ($this->connector)('odoo')->pushVendorBill(billFor($this->doc, 'odoo'));

        expect($r->alreadyExisted)->toBeTrue()->and($r->externalRef)->toBe('odoo:account.move:900')->and(odooCalls($calls, 'account.move.create'))->toBe([]);
    });

    it('syncs suppliers, confirmed POs with line refs, and incoming receipts', function () {
        fakeOdoo([
            'res.partner.search_read' => [['id' => 55, 'name' => 'Kestrel Industrial Parts LLC', 'vat' => '88-1234567', 'email' => false]],
            'purchase.order.search_read' => [['id' => 9, 'name' => 'PO-1008', 'partner_id' => [55, 'Kestrel'], 'date_order' => '2026-10-31 09:00:00', 'date_planned' => false, 'currency_id' => [1, 'USD'], 'state' => 'purchase']],
            'purchase.order.line.search_read' => [
                ['id' => 802, 'order_id' => [9, 'PO-1008'], 'sequence' => 10, 'product_id' => [12, 'Hose'], 'name' => 'Hydraulic hose', 'product_qty' => 10, 'price_unit' => 38.5, 'product_uom' => [1, 'Units']],
                ['id' => 801, 'order_id' => [9, 'PO-1008'], 'sequence' => 5, 'product_id' => [11, 'Bearing'], 'name' => 'Bearing', 'product_qty' => 40, 'price_unit' => 6.75, 'product_uom' => [1, 'Units']],
            ],
            'product.product.read' => [['id' => 11, 'default_code' => 'KIP-2040'], ['id' => 12, 'default_code' => 'KIP-3300']],
            'stock.picking.search_read' => [['id' => 31, 'name' => 'WH/IN/00007', 'origin' => 'PO-1008', 'date_done' => '2026-11-08 10:00:00']],
            'stock.move.search_read' => [['picking_id' => [31, 'WH/IN/00007'], 'product_id' => [12, 'Hose'], 'name' => 'Hydraulic hose', 'quantity' => 6, 'purchase_line_id' => [802, 'x']]],
        ]);
        $c = ($this->connector)('odoo');
        $po = $c->syncPurchaseOrders()[0];
        $receipt = $c->syncReceipts()[0];

        expect($c->syncVendors()[0])->toMatchArray(['erp_ref' => 'odoo:55', 'tax_id' => '88-1234567', 'email' => null])
            ->and($po['header'])->toMatchArray(['po_number' => 'PO-1008', 'vendor_erp_ref' => 'odoo:55', 'erp_ref' => 'odoo:po:9', 'currency' => 'USD', 'status' => 'open'])
            ->and($po['lines'][0])->toMatchArray(['line_no' => 1, 'sku' => 'KIP-2040', 'unit_price_minor' => 675, 'erp_ref' => 'odoo:pol:801']) // ordered by sequence
            ->and($receipt['header'])->toMatchArray(['grn_number' => 'WH/IN/00007', 'po_number' => 'PO-1008'])
            ->and($receipt['lines'][0])->toMatchArray(['po_line_erp_ref' => 'odoo:pol:802', 'quantity' => 6.0, 'sku' => 'KIP-3300']);
    });

    it('falls back to the PO lines\' received quantities when Inventory is not installed', function () {
        fakeOdoo([
            'stock.picking.search_read' => new RuntimeException("Object stock.picking doesn't exist"),
            'purchase.order.line.search_read' => [['id' => 802, 'order_id' => [9, 'PO-1008'], 'name' => 'Hose', 'qty_received' => 6, 'product_id' => [12, 'Hose']]],
        ]);
        $r = ($this->connector)('odoo')->syncReceipts();

        expect($r)->toHaveCount(1)->and($r[0]['header']['grn_number'])->toBe('PO-1008/RCV')->and($r[0]['lines'][0]['po_line_erp_ref'])->toBe('odoo:pol:802');
    });

    it('maps state and payment_state to draft / posted / paid / cancelled', function (string $state, string $payment, string $expected) {
        fakeOdoo(['account.move.read' => [['state' => $state, 'payment_state' => $payment, 'write_date' => '2026-11-14 10:00:00']]]);
        expect(($this->connector)('odoo')->getBillStatus('odoo:account.move:901')->state)->toBe($expected);
    })->with([['draft', 'not_paid', 'draft'], ['posted', 'not_paid', 'posted'], ['posted', 'partial', 'posted'], ['posted', 'in_payment', 'paid'], ['posted', 'paid', 'paid'], ['cancel', 'not_paid', 'cancelled']]);

    it('surfaces Odoo errors as non-retryable and reports the connection state', function () {
        $denied = true;
        fakeOdoo(['account.move.check_access_rights' => function () use (&$denied) {
            if ($denied) {
                throw new RuntimeException('Access Denied');
            }

            return true;
        }]);
        expect(($this->connector)('odoo')->testConnection())->toMatchArray(['ok' => false, 'message' => 'Odoo: Access Denied']);
        try {
            ($this->connector)('odoo')->pushVendorBill(billFor($this->doc, 'odoo'));
        } catch (ErpException $e) {
            expect($e->retryable)->toBeFalse();
        }

        $denied = false;
        expect(($this->connector)('odoo')->testConnection())->toMatchArray(['ok' => true])
            ->and(($this->connector)('odoo')->testConnection()['message'])->toContain('Odoo 17.0');
    });

    it('seeds Odoo with the demo vendors, products and 10 confirmed purchase orders with receipts', function () {
        $poId = 100;
        $calls = fakeOdoo([
            'res.partner.search' => [], 'res.partner.create' => 55, 'product.product.search' => [], 'product.product.create' => 11,
            'purchase.order.search' => [], 'purchase.order.create' => function () use (&$poId) { return ++$poId; }, 'purchase.order.button_confirm' => true,
            'res.currency.search' => [1], 'res.currency.write' => true,
            'stock.picking.search' => [31], 'stock.move.search_read' => [['id' => 41, 'product_id' => [11, 'x'], 'product_uom_qty' => 1]],
            'product.product.read' => [['id' => 11, 'default_code' => 'KIP-1001']], 'stock.move.write' => true, 'stock.picking.button_validate' => true,
        ]);
        $counts = app(SeedOdoo::class)->seed(new OdooClient(config('extractiq.erp.drivers.odoo')), json_decode(file_get_contents(base_path('../samples/erp/seed.json')), true));

        expect($counts['vendors']['created'])->toBe(4)->and($counts['products']['created'])->toBe(51)->and($counts['purchase_orders']['created'])->toBe(10)
            ->and($counts['receipts']['created'])->toBe(4)
            ->and(odooCalls($calls, 'purchase.order.button_confirm'))->toHaveCount(10)
            ->and(odooCalls($calls, 'purchase.order.create')[0]['args'][0])->toMatchArray(['name' => 'PO-1001', 'partner_id' => 55])
            ->and(odooCalls($calls, 'stock.picking.button_validate')[0]['kwargs']['context'])->toMatchArray(['skip_backorder' => true]);
    });
});

describe('quickbooks', function () {
    beforeEach(function () {
        config(['extractiq.erp.drivers.quickbooks' => array_merge(config('extractiq.erp.drivers.quickbooks'), [
            'client_id' => 'cid', 'client_secret' => 'csecret', 'base_url' => 'https://qbo.test', 'app_url' => 'https://app.qbo.test',
        ])]);
        $this->connection = ErpConnection::for($this->company->id, 'quickbooks');
        $this->connect = fn (string $expires = '+1 hour') => $this->connection->update(['credentials' => [
            'realm_id' => '9130', 'access_token' => 'tok', 'refresh_token' => 'rtok', 'expires_at' => now()->modify($expires)->toIso8601String(),
        ]]);
    });

    it('runs the OAuth 2.0 authorization-code flow and stores the tokens encrypted', function () {
        $url = app(QuickBooksOAuth::class)->authorizeUrl($this->company->id, 1);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        expect($url)->toStartWith('https://appcenter.intuit.com/connect/oauth2')->and($q)->toMatchArray(['client_id' => 'cid', 'scope' => 'com.intuit.quickbooks.accounting', 'response_type' => 'code']);

        Http::fake(['oauth.platform.intuit.com/*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400])]);
        app(QuickBooksOAuth::class)->complete($q['state'], 'auth-code', '9130');

        $raw = DB::table('erp_connections')->where('id', $this->connection->id)->value('credentials');
        expect($this->connection->fresh()->credentials)->toMatchArray(['realm_id' => '9130', 'access_token' => 'AT', 'refresh_token' => 'RT'])
            ->and($this->connection->fresh()->status)->toBe('connected')->and($raw)->not->toContain('AT');
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'auth-code' && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:csecret')));

        // A state can be used once.
        expect(fn () => app(QuickBooksOAuth::class)->complete($q['state'], 'auth-code', '9130'))->toThrow(ErpException::class);
    });

    it('refreshes an expired access token before calling the API', function () {
        ($this->connect)('-5 minutes');
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response(['access_token' => 'NEW', 'refresh_token' => 'RT2', 'expires_in' => 3600]),
            'qbo.test/*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Sandbox Co']]),
        ]);

        expect(($this->connector)('quickbooks')->testConnection())->toMatchArray(['ok' => true])
            ->and($this->connection->fresh()->credentials['access_token'])->toBe('NEW');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'qbo.test') && $r->hasHeader('Authorization', 'Bearer NEW'));
    });

    it('creates a Bill linked to the PurchaseOrder, item lines where QuickBooks knows the SKU, and uploads the PDF', function () {
        ($this->connect)();
        ErpConnection::for($this->company->id, 'quickbooks')->update(['mapping' => ['tax_codes' => [['rate' => 8, 'code' => 'TAX']], 'cost_centres' => [['vendor_id' => $this->vendor->id, 'cost_centre' => 'Plant', 'account' => null]]]]);
        Http::fake([
            '*/query?*Vendor*' => Http::response(['QueryResponse' => ['Vendor' => [['Id' => '58']]]]),
            '*/query?*Bill*' => Http::response(['QueryResponse' => []]),
            '*/query?*PurchaseOrder*' => Http::response(['QueryResponse' => ['PurchaseOrder' => [['Id' => '300', 'Line' => [['Id' => '1', 'ItemBasedExpenseLineDetail' => ['ItemRef' => ['value' => '19']]]]]]]]),
            '*/query?*Item*A-1*' => Http::response(['QueryResponse' => ['Item' => [['Id' => '19']]]]),
            '*/query?*Item*' => Http::response(['QueryResponse' => []]),
            '*/query?*Class*' => Http::response(['QueryResponse' => ['Class' => [['Id' => '4']]]]),
            '*/bill?*' => Http::response(['Bill' => ['Id' => '146']]),
            '*/upload?*' => Http::response(['AttachableResponse' => [['Attachable' => ['Id' => '700']]]]),
        ]);

        $r = ($this->connector)('quickbooks')->pushVendorBill(billFor($this->doc, 'quickbooks'));

        expect($r->externalRef)->toBe('qbo:bill:146')->and($r->url)->toBe('https://app.qbo.test/app/bill?txnId=146')->and($r->response['attachable_id'])->toBe('700');
        Http::assertSent(function (Request $req) {
            if (! str_contains($req->url(), '/v3/company/9130/bill')) {
                return false;
            }
            [$item, $account] = $req['Line'];

            return $req['VendorRef']['value'] === '58' && $req['DocNumber'] === 'INV-77' && $req->hasHeader('Authorization', 'Bearer tok')
                && $item['DetailType'] === 'ItemBasedExpenseLineDetail' && $item['ItemBasedExpenseLineDetail']['ItemRef']['value'] === '19'
                && $item['LinkedTxn'][0] === ['TxnId' => '300', 'TxnType' => 'PurchaseOrder', 'TxnLineId' => '1']
                && $item['ItemBasedExpenseLineDetail']['TaxCodeRef']['value'] === 'TAX' && $item['ItemBasedExpenseLineDetail']['ClassRef']['value'] === '4'
                && $account['DetailType'] === 'AccountBasedExpenseLineDetail' && $account['AccountBasedExpenseLineDetail']['AccountRef']['value'] === '7'
                && $account['LinkedTxn'][0]['TxnId'] === '300';
        });
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/upload') && str_contains($req->body(), '"type":"Bill","value":"146"'));
    });

    it('syncs vendors and purchase orders, and reads payment from the bill balance', function () {
        ($this->connect)();
        Http::fake([
            '*/query?*Vendor*' => Http::response(['QueryResponse' => ['Vendor' => [['Id' => '58', 'DisplayName' => 'Kestrel Industrial Parts LLC', 'CompanyName' => 'Kestrel Parts', 'PrimaryEmailAddr' => ['Address' => 'ap@kestrel.example'], 'CurrencyRef' => ['value' => 'USD']]]]]),
            '*/query?*from+PurchaseOrder*' => Http::response(['QueryResponse' => ['PurchaseOrder' => [['Id' => '300', 'DocNumber' => 'PO-1008', 'VendorRef' => ['value' => '58'], 'TxnDate' => '2026-10-31', 'CurrencyRef' => ['value' => 'USD'], 'POStatus' => 'Open',
                'Line' => [['Id' => '1', 'DetailType' => 'ItemBasedExpenseLineDetail', 'Description' => 'Bearing', 'Amount' => 270, 'ItemBasedExpenseLineDetail' => ['ItemRef' => ['value' => '19'], 'Qty' => 40, 'UnitPrice' => 6.75]]]]]]]),
            '*/query?*Item*' => Http::response(['QueryResponse' => ['Item' => [['Id' => '19', 'Name' => 'Bearing', 'Sku' => 'KIP-2040']]]]),
            '*/bill/146?*' => Http::response(['Bill' => ['Id' => '146', 'Balance' => 0]]),
        ]);
        $c = ($this->connector)('quickbooks');

        expect($c->syncVendors()[0])->toMatchArray(['erp_ref' => 'qbo:58', 'email' => 'ap@kestrel.example', 'aliases' => ['Kestrel Parts']])
            ->and($c->syncPurchaseOrders()[0]['lines'][0])->toMatchArray(['sku' => 'KIP-2040', 'quantity' => 40.0, 'unit_price_minor' => 675, 'erp_ref' => 'qbo:poline:1'])
            ->and($c->syncReceipts())->toBe([])
            ->and($c->getBillStatus('qbo:bill:146')->state)->toBe('paid');
    });

    it('is not retryable until someone connects it', function () {
        expect(fn () => ($this->connector)('quickbooks')->pushVendorBill(billFor($this->doc, 'quickbooks')))
            ->toThrow(fn (ErpException $e) => expect($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('Connect to QuickBooks'));
    });
});

describe('webhook', function () {
    beforeEach(fn () => config(['extractiq.erp.drivers.webhook' => ['url' => 'https://hooks.example/erp', 'secret' => 's3cret']]));

    it('POSTs signed JSON with the document uuid as idempotency key, mapped field names and a signed file link', function () {
        ErpConnection::for($this->company->id, 'webhook')->update(['mapping' => ['fields' => ['document_number' => 'invoice_ref', 'po_number' => 'purchase_order']]]);
        Http::fake(['hooks.example/*' => Http::response(['id' => 'BILL-42', 'url' => 'https://erp.example/bills/42'], 201)]);

        $r = ($this->connector)('webhook')->pushVendorBill(billFor($this->doc, 'webhook'));

        expect($r->externalRef)->toBe('BILL-42')->and($r->url)->toBe('https://erp.example/bills/42')->and($r->httpStatus)->toBe(201);
        $sent = null;
        Http::assertSent(function (Request $req) use (&$sent) {
            $sent = $req;

            return $req->header('Idempotency-Key')[0] === $this->doc->uuid
                && $req->header('X-ExtractIQ-Signature')[0] === 'sha256='.hash_hmac('sha256', $req->body(), 's3cret')
                && $req['data']['invoice_ref'] === 'INV-77' && $req['data']['purchase_order'] === 'PO-9' && $req['id'] === $this->doc->uuid;
        });

        // The signed link serves the original file; without the signature it's refused.
        $link = $sent['data']['attachment']['url'];
        $this->get($link)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(strtok($link, '?'))->assertForbidden();
    });

    it('treats 4xx as non-retryable and 5xx as retryable', function () {
        Http::fake(['hooks.example/*' => Http::sequence()->push('nope', 422)->push('', 503)]);
        $bill = billFor($this->doc, 'webhook');

        expect(fn () => ($this->connector)('webhook')->pushVendorBill($bill))->toThrow(fn (ErpException $e) => expect($e->retryable)->toBeFalse()->and($e->httpStatus)->toBe(422))
            ->and(fn () => ($this->connector)('webhook')->pushVendorBill($bill))->toThrow(fn (ErpException $e) => expect($e->retryable)->toBeTrue());
    });

    it('never builds a connector it does not know', function () {
        expect(fn () => app(ErpManager::class)->make('sap', new ErpContext($this->company->id, ErpConnection::for($this->company->id, 'mock'))))->toThrow(InvalidArgumentException::class);
        expect(new WebhookConnector([]))->toBeInstanceOf(WebhookConnector::class);
    });
});

