<?php

namespace App\Services\Erp\Drivers;

use App\Services\Erp\BillStatus;
use App\Services\Erp\ErpBill;
use App\Services\Erp\ErpConnector;
use App\Services\Erp\ErpContext;
use App\Services\Erp\ErpException;
use App\Services\Erp\PushResult;
use App\Services\Erp\QuickBooksOAuth;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * QuickBooks Online Accounting API (v3), OAuth 2.0 tokens from QuickBooksOAuth.
 *
 * Bills: a Bill for the Vendor, linked to the PurchaseOrder — lines whose SKU is a QuickBooks Item
 * become ItemBasedExpenseLineDetail lines with LinkedTxn to the PO line; others are account-based
 * lines on the mapped GL account. Tax codes and classes (cost centres) come from the mapping. The PDF
 * is uploaded as an Attachable on the bill. Idempotent through the requestid header and a DocNumber
 * lookup. QuickBooks has no goods receipts, so syncReceipts returns nothing (2-way, or receipts
 * from documents/CSV).
 */
class QuickBooksConnector implements ErpConnector
{
    private ?array $creds = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(private readonly array $config, private readonly ?ErpContext $ctx = null) {}

    public function name(): string
    {
        return 'quickbooks';
    }

    public function capabilities(): array
    {
        return ['sync_vendors' => true, 'sync_purchase_orders' => true, 'sync_receipts' => false, 'bill_status' => true, 'attachments' => true];
    }

    public function testConnection(): array
    {
        try {
            $info = $this->request('get', 'companyinfo/'.$this->realm());

            return ['ok' => true, 'message' => 'Connected to '.data_get($info, 'CompanyInfo.CompanyName', 'QuickBooks').' (realm '.$this->realm().').'];
        } catch (ErpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncVendors(): array
    {
        return array_map(fn ($v) => [
            'name' => $v['DisplayName'], 'tax_id' => $v['TaxIdentifier'] ?? null, 'email' => data_get($v, 'PrimaryEmailAddr.Address'),
            'default_currency' => data_get($v, 'CurrencyRef.value'), 'erp_ref' => "qbo:{$v['Id']}",
            'aliases' => array_values(array_filter(array_unique([$v['CompanyName'] ?? null]), fn ($a) => $a && $a !== $v['DisplayName'])),
        ], $this->query('select * from Vendor where Active = true maxresults 1000', 'Vendor'));
    }

    public function syncPurchaseOrders(): array
    {
        $orders = $this->query('select * from PurchaseOrder maxresults 1000', 'PurchaseOrder');
        $itemIds = collect($orders)->flatMap(fn ($o) => collect($o['Line'] ?? [])->pluck('ItemBasedExpenseLineDetail.ItemRef.value'))->filter()->unique()->values();
        $skus = $itemIds->isEmpty() ? collect() : collect($this->query("select * from Item where Id in ('".$itemIds->implode("','")."')", 'Item'))
            ->mapWithKeys(fn ($i) => [$i['Id'] => $i['Sku'] ?? $i['Name']]);

        return array_map(function ($o) use ($skus) {
            $currency = data_get($o, 'CurrencyRef.value');
            $lines = collect($o['Line'] ?? [])->filter(fn ($l) => in_array($l['DetailType'] ?? '', ['ItemBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail'], true))->values();

            return [
                'header' => [
                    'po_number' => $o['DocNumber'] ?? "QBO-PO-{$o['Id']}", 'vendor_erp_ref' => 'qbo:'.data_get($o, 'VendorRef.value'), 'vendor_name' => data_get($o, 'VendorRef.name'),
                    'order_date' => $o['TxnDate'] ?? null, 'expected_date' => $o['DueDate'] ?? null, 'currency' => $currency,
                    'status' => ($o['POStatus'] ?? 'Open') === 'Closed' ? 'closed' : 'open', 'erp_ref' => "qbo:po:{$o['Id']}",
                ],
                'lines' => $lines->map(function ($l, $i) use ($skus, $currency) {
                    $item = data_get($l, 'ItemBasedExpenseLineDetail.ItemRef.value');
                    $qty = (float) (data_get($l, 'ItemBasedExpenseLineDetail.Qty') ?? 1);
                    $price = data_get($l, 'ItemBasedExpenseLineDetail.UnitPrice') ?? ($l['Amount'] ?? 0) / max($qty, 1);

                    return [
                        'line_no' => $i + 1, 'sku' => $item ? ($skus[$item] ?? null) : null, 'description' => (string) ($l['Description'] ?? data_get($l, 'ItemBasedExpenseLineDetail.ItemRef.name', 'Line')),
                        'quantity' => $qty, 'unit_price_minor' => Money::toMinor($price, $currency), 'erp_ref' => "qbo:poline:{$l['Id']}",
                    ];
                })->all(),
            ];
        }, $orders);
    }

    public function syncReceipts(): array
    {
        return [];
    }

    public function pushVendorBill(ErpBill $bill): PushResult
    {
        $this->cache = [];
        $vendorId = $bill->vendorRef && preg_match('/^qbo:(\d+)$/', $bill->vendorRef, $m) ? $m[1] : $this->vendor($bill);
        $docNumber = mb_substr($bill->fields['DocNumber'] ?? $bill->number, 0, 21);

        $existing = $this->query("select Id from Bill where DocNumber = '".$this->q($docNumber)."' and VendorRef = '{$vendorId}'", 'Bill');
        if ($id = $existing[0]['Id'] ?? null) {
            return new PushResult("qbo:bill:$id", ['Id' => $id], alreadyExisted: true, url: $this->url($id), request: ['query' => "Bill $docNumber"], httpStatus: 200, state: 'posted');
        }

        [$poId, $poLines] = $this->purchaseOrder($bill);
        $payload = array_filter([
            'VendorRef' => ['value' => $vendorId],
            'DocNumber' => $docNumber,
            'TxnDate' => $bill->fields['TxnDate'] ?? $bill->date,
            'DueDate' => $bill->fields['DueDate'] ?? null,
            'CurrencyRef' => ['value' => $bill->currency],
            'PrivateNote' => $bill->fields['PrivateNote'] ?? null,
            'Line' => array_map(fn ($l) => $this->line($l, $poId, $poLines), $bill->lines),
        ]);

        $res = $this->request('post', 'bill', $payload, ['requestid' => $bill->idempotencyKey]);
        $id = (string) data_get($res, 'Bill.Id');
        $attachable = null;
        if ($contents = $bill->attachmentContents()) {
            $attachable = $this->attach($id, $bill->attachment['filename'], $bill->attachment['mime'], $contents);
        }

        return new PushResult("qbo:bill:$id", $res + ['attachable_id' => $attachable], url: $this->url($id), request: $payload, httpStatus: 200, state: 'posted');
    }

    public function getBillStatus(string $ref): ?BillStatus
    {
        if (! preg_match('/^qbo:bill:(\d+)$/', $ref, $m)) {
            return null;
        }
        $b = data_get($this->request('get', "bill/{$m[1]}"), 'Bill');
        if (! $b) {
            return null;
        }

        return (float) ($b['Balance'] ?? 1) <= 0.0
            ? new BillStatus('paid', null, 'Balance 0')
            : new BillStatus('posted', null, 'Balance '.$b['Balance']);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function line(array $l, ?string $poId, array $poLines): array
    {
        $item = $l['sku'] ? $this->lookup("select Id from Item where Sku = '".$this->q($l['sku'])."'", 'Item') : null;
        $class = $l['cost_centre'] ? $this->lookup("select Id from Class where Name = '".$this->q($l['cost_centre'])."'", 'Class') : null;
        $common = array_filter([
            'TaxCodeRef' => $l['tax_code'] ? ['value' => $l['tax_code']] : null,
            'ClassRef' => $class ? ['value' => $class] : null,
        ]);
        $base = ['Amount' => round($l['amount'], 2), 'Description' => trim(($l['sku'] ? "[{$l['sku']}] " : '').$l['description'])];

        if ($item) {
            $poLine = collect($poLines)->first(fn ($pl) => data_get($pl, 'ItemBasedExpenseLineDetail.ItemRef.value') === $item);

            return $base + array_filter([
                'DetailType' => 'ItemBasedExpenseLineDetail',
                'ItemBasedExpenseLineDetail' => ['ItemRef' => ['value' => $item], 'Qty' => $l['quantity'], 'UnitPrice' => $l['unit_price']] + $common,
                // Links the bill line to the PO line, which updates the PO's received/billed state in QuickBooks.
                'LinkedTxn' => $poId ? [array_filter(['TxnId' => $poId, 'TxnType' => 'PurchaseOrder', 'TxnLineId' => $poLine['Id'] ?? null])] : null,
            ]);
        }

        return $base + array_filter([
            'DetailType' => 'AccountBasedExpenseLineDetail',
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $l['account'] ?? $this->config['expense_account_id']]] + $common,
            'LinkedTxn' => $poId ? [['TxnId' => $poId, 'TxnType' => 'PurchaseOrder']] : null,
        ]);
    }

    /** @return array{0: ?string, 1: list<array>} */
    private function purchaseOrder(ErpBill $bill): array
    {
        if ($bill->poRef && preg_match('/^qbo:po:(\d+)$/', $bill->poRef, $m)) {
            $po = data_get($this->request('get', "purchaseorder/{$m[1]}"), 'PurchaseOrder');
        } elseif ($bill->poNumber) {
            $po = $this->query("select * from PurchaseOrder where DocNumber = '".$this->q($bill->poNumber)."'", 'PurchaseOrder')[0] ?? null;
        }

        return empty($po) ? [null, []] : [(string) $po['Id'], $po['Line'] ?? []];
    }

    private function vendor(ErpBill $bill): string
    {
        $found = $this->lookup("select Id from Vendor where DisplayName = '".$this->q($bill->vendorName)."'", 'Vendor');

        return $found ?? (string) data_get($this->request('post', 'vendor', array_filter([
            'DisplayName' => $bill->vendorName, 'CompanyName' => $bill->vendorName, 'TaxIdentifier' => $bill->vendorTaxId,
        ])), 'Vendor.Id');
    }

    private function attach(string $billId, string $filename, string $mime, string $contents): ?string
    {
        $meta = json_encode(['AttachableRef' => [['EntityRef' => ['type' => 'Bill', 'value' => $billId]]], 'FileName' => $filename, 'ContentType' => $mime]);
        $res = $this->send(fn ($http, $url) => $http->attach('file_metadata_01', $meta, 'metadata.json', ['Content-Type' => 'application/json'])
            ->attach('file_content_01', $contents, $filename, ['Content-Type' => $mime])->post($url), 'upload');

        return data_get($res, 'AttachableResponse.0.Attachable.Id');
    }

    private function lookup(string $query, string $entity): ?string
    {
        return $this->cache[$query] ??= ($this->query($query, $entity)[0]['Id'] ?? null);
    }

    /** @return list<array> */
    private function query(string $q, string $entity): array
    {
        return data_get($this->request('get', 'query', null, ['query' => $q]), "QueryResponse.$entity", []);
    }

    private function q(string $v): string
    {
        return str_replace("'", "\\'", $v);
    }

    private function url(string $id): string
    {
        return rtrim((string) $this->config['app_url'], '/')."/app/bill?txnId=$id";
    }

    private function realm(): string
    {
        return (string) $this->credentials()['realm_id'];
    }

    private function credentials(bool $refresh = false): array
    {
        $connection = $this->ctx ? $this->ctx->connection : throw new ErpException('QuickBooks needs a company connection.', retryable: false);
        if ($refresh) {
            return $this->creds = app(QuickBooksOAuth::class)->refresh($connection);
        }

        return $this->creds ??= app(QuickBooksOAuth::class)->accessToken($connection);
    }

    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        return $this->send(fn ($http, $url) => $method === 'get' ? $http->get($url) : $http->post($url, $body), $path, $query);
    }

    /** One call, refreshing the token once on a 401. */
    private function send(callable $call, string $path, array $query = []): array
    {
        foreach ([false, true] as $refreshed) {
            $creds = $this->credentials($refreshed);
            $url = rtrim((string) $this->config['base_url'], '/')."/v3/company/{$creds['realm_id']}/$path?".http_build_query($query + ['minorversion' => 73]);
            try {
                /** @var Response $response */
                $response = $call(Http::withToken($creds['access_token'])->acceptJson()->timeout(30), $url);
            } catch (ConnectionException $e) {
                throw new ErpException('QuickBooks unreachable: '.$e->getMessage());
            }
            if ($response->status() === 401 && ! $refreshed) {
                continue;
            }
            if ($response->failed()) {
                throw new ErpException(
                    'QuickBooks: '.data_get($response->json(), 'Fault.Error.0.Detail', data_get($response->json(), 'Fault.Error.0.Message', "HTTP {$response->status()}")),
                    retryable: $response->serverError() || $response->status() === 429, response: (array) $response->json(), httpStatus: $response->status(),
                );
            }

            return (array) $response->json();
        }

        return [];
    }
}
