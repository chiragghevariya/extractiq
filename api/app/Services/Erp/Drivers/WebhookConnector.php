<?php

namespace App\Services\Erp\Drivers;

use App\Services\Erp\BillStatus;
use App\Services\Erp\ErpBill;
use App\Services\Erp\ErpConnector;
use App\Services\Erp\ErpContext;
use App\Services\Erp\ErpException;
use App\Services\Erp\PushResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * POSTs the bill as signed JSON to any URL (Zapier, n8n, a custom ERP bridge).
 *   X-ExtractIQ-Signature: sha256=HMAC_SHA256(raw body, ERP_WEBHOOK_SECRET)
 *   Idempotency-Key: the document uuid
 * Header fields use the names from the field mapping; the original file is a signed link valid
 * for 7 days. One-way: nothing to sync back and no bill status.
 */
class WebhookConnector implements ErpConnector
{
    public function __construct(private readonly array $config, private readonly ?ErpContext $ctx = null) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function capabilities(): array
    {
        return ['sync_vendors' => false, 'sync_purchase_orders' => false, 'sync_receipts' => false, 'bill_status' => false, 'attachments' => true];
    }

    public function testConnection(): array
    {
        if (empty($this->config['url'])) {
            return ['ok' => false, 'message' => 'ERP_WEBHOOK_URL is not set.'];
        }
        try {
            $res = $this->post(['event' => 'ping', 'sent_at' => now()->toIso8601String()], 'ping-'.now()->timestamp);
        } catch (ErpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Webhook answered HTTP '.$res->status().' at '.parse_url($this->config['url'], PHP_URL_HOST).'.'];
    }

    public function syncVendors(): array
    {
        return [];
    }

    public function syncPurchaseOrders(): array
    {
        return [];
    }

    public function syncReceipts(): array
    {
        return [];
    }

    public function getBillStatus(string $ref): ?BillStatus
    {
        return null;
    }

    public function pushVendorBill(ErpBill $bill): PushResult
    {
        if (empty($this->config['url'])) {
            throw new ErpException('ERP_WEBHOOK_URL is not set.', retryable: false);
        }
        $payload = ['event' => 'bill.approved', 'id' => $bill->idempotencyKey, 'data' => $this->data($bill)];
        $res = $this->post($payload, $bill->idempotencyKey);
        $ref = (string) ($res->json('id') ?? $res->json('external_ref') ?? $bill->idempotencyKey);

        return new PushResult($ref, (array) ($res->json() ?? ['body' => mb_substr($res->body(), 0, 2000)]),
            url: $res->json('url'), request: $payload, httpStatus: $res->status());
    }

    private function data(ErpBill $bill): array
    {
        $data = $bill->toArray();
        unset($data['fields']);
        // Mapped header fields first, under the receiver's names.
        $data = $bill->fields + $data;
        if ($bill->attachment) {
            $data['attachment'] = [
                'filename' => $bill->attachment['filename'], 'mime' => $bill->attachment['mime'],
                'url' => $this->ctx ? URL::temporarySignedRoute('erp.file', now()->addDays(7), ['uuid' => $bill->idempotencyKey]) : null,
            ];
        }

        return $data;
    }

    private function post(array $payload, string $key): \Illuminate\Http\Client\Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Idempotency-Key' => $key,
                    'X-ExtractIQ-Signature' => 'sha256='.hash_hmac('sha256', (string) $body, (string) ($this->config['secret'] ?? '')),
                    'User-Agent' => 'ExtractIQ-Webhook/1',
                ])
                ->withBody((string) $body, 'application/json')
                ->post($this->config['url']);
        } catch (ConnectionException $e) {
            throw new ErpException('Webhook unreachable: '.$e->getMessage(), request: $payload);
        }
        if ($response->failed()) {
            throw new ErpException("Webhook returned HTTP {$response->status()}.", retryable: $response->serverError() || $response->status() === 429,
                response: ['body' => mb_substr($response->body(), 0, 2000)], request: $payload, httpStatus: $response->status());
        }

        return $response;
    }
}
