<?php

namespace App\Services\Erp;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\Vendor;
use App\Services\Matching\MasterData;
use App\Services\Matching\Rematcher;
use App\Services\Pipeline\EventRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduled pull (every 15 minutes, or "Sync now"): vendors, purchase orders and receipts from
 * the ERP into master data, then the status of every bill we pushed (pushed → posted → paid).
 * Each part runs on its own, so a failure in one is reported without losing the others. Invoices
 * waiting in match_exception for the synced POs are matched again.
 */
class ErpSync
{
    public function __construct(
        private readonly ErpManager $erp,
        private readonly MasterData $master,
        private readonly Rematcher $rematcher,
        private readonly EventRecorder $events,
    ) {}

    public function run(int $companyId, string $trigger = 'schedule'): ErpSyncRun
    {
        $connection = ErpConnection::activeFor($companyId);
        $run = ErpSyncRun::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'connector' => $connection->connector, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now(),
        ]);
        $start = hrtime(true);
        $counts = [];
        $errors = [];
        $poIds = [];

        try {
            $connector = $this->erp->forCompany($companyId, $connection->connector);
        } catch (Throwable $e) {
            return $this->finish($run, $connection, [], [$e->getMessage()], $start);
        }
        $caps = $connector->capabilities();

        $part = function (string $name, bool $enabled, callable $fn) use (&$counts, &$errors) {
            if (! $enabled) {
                return;
            }
            try {
                $counts[$name] = $fn();
            } catch (Throwable $e) {
                $errors[] = ucfirst(str_replace('_', ' ', $name)).': '.$e->getMessage();
                Log::warning('ERP sync part failed', ['part' => $name, 'error' => $e->getMessage()]);
            }
        };

        $part('vendors', $caps['sync_vendors'] ?? false, function () use ($connector, $companyId) {
            $c = ['created' => 0, 'updated' => 0];
            foreach ($connector->syncVendors() as $row) {
                $v = $this->master->upsertVendor($companyId, $row, 'erp');
                $c[$v->wasRecentlyCreated ? 'created' : 'updated']++;
            }

            return $c;
        });

        $part('purchase_orders', $caps['sync_purchase_orders'] ?? false, function () use ($connector, $companyId, &$poIds) {
            $c = ['created' => 0, 'updated' => 0];
            foreach ($connector->syncPurchaseOrders() as $row) {
                $h = $row['header'];
                $vendor = Vendor::withoutGlobalScopes()->where('company_id', $companyId)->where('erp_ref', $h['vendor_erp_ref'] ?? '-')->first()
                    ?? (! empty($h['vendor_name']) ? Vendor::withoutGlobalScopes()->where('company_id', $companyId)->where('normalized_name', Vendor::normalize($h['vendor_name']))->first() : null);
                $existed = \App\Models\PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)
                    ->where('number_key', \App\Models\PurchaseOrder::key($h['po_number']))->exists();
                $po = $this->master->upsertPurchaseOrder($companyId, array_filter([
                    'po_number' => $h['po_number'], 'vendor_id' => $vendor?->id, 'order_date' => $h['order_date'] ?? null,
                    'expected_date' => $h['expected_date'] ?? null, 'currency' => $h['currency'] ?? null, 'status' => $h['status'] ?? 'open', 'erp_ref' => $h['erp_ref'] ?? null,
                ], fn ($v) => $v !== null), $row['lines'], 'erp');
                $poIds[] = $po->id;
                $c[$existed ? 'updated' : 'created']++;
            }

            return $c;
        });

        $part('receipts', $caps['sync_receipts'] ?? false, function () use ($connector, $companyId, &$poIds) {
            $c = ['created' => 0, 'updated' => 0];
            foreach ($connector->syncReceipts() as $row) {
                $existed = \App\Models\GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)
                    ->where('number_key', \App\Models\PurchaseOrder::key($row['header']['grn_number']))->exists();
                $grn = $this->master->upsertGoodsReceipt($companyId, $row['header'], $row['lines'], 'erp');
                if ($grn->purchase_order_id) {
                    $poIds[] = $grn->purchase_order_id;
                }
                $c[$existed ? 'updated' : 'created']++;
            }

            return $c;
        });

        $part('bills', $caps['bill_status'] ?? false, fn () => $this->billStatuses($companyId, $connector));

        if ($poIds) {
            $counts['rematched'] = $this->rematcher->forPurchaseOrders($companyId, array_values(array_unique($poIds)));
        }

        return $this->finish($run, $connection, $counts, $errors, $start);
    }

    /** Read back every open bill we pushed through this connector. @return array{checked: int, posted: int, paid: int, cancelled: int} */
    public function billStatuses(int $companyId, ErpConnector $connector): array
    {
        $c = ['checked' => 0, 'posted' => 0, 'paid' => 0, 'cancelled' => 0];
        $docs = Document::withoutGlobalScopes()->where('company_id', $companyId)->where('erp_connector', $connector->name())
            ->whereIn('status', [DocumentStatus::Pushed->value, DocumentStatus::Posted->value])->whereNotNull('erp_ref')->get();
        foreach ($docs as $doc) {
            $c['checked']++;
            $status = $connector->getBillStatus((string) $doc->erp_ref);
            if (! $status || $status->state === $doc->erp_status && $status->state !== 'paid') {
                continue;
            }
            $from = $doc->status;
            $doc->fill(['erp_status' => $status->state, 'erp_status_at' => now()]);
            if ($status->state === 'posted' && $doc->status === DocumentStatus::Pushed) {
                $doc->transitionTo(DocumentStatus::Posted);
                $c['posted']++;
            } elseif ($status->state === 'paid') {
                $doc->paid_at = $status->paidAt ?? now();
                $doc->transitionTo(DocumentStatus::Paid);
                $c['paid']++;
            } else {
                if ($status->state === 'cancelled') {
                    $c['cancelled']++;
                }
                $doc->save();
            }
            $this->events->record($doc, 'erp_status', 'info', $from->value, $doc->status !== $from ? $doc->status->value : null,
                meta: ['erp_status' => $status->state, 'detail' => $status->detail, 'ref' => $doc->erp_ref]);
        }

        return $c;
    }

    private function finish(ErpSyncRun $run, ErpConnection $connection, array $counts, array $errors, int $start): ErpSyncRun
    {
        // A part that didn't reach the ERP (no bills to check) doesn't make a broken run a partial success.
        $reached = array_diff(array_keys($counts), array_filter(['rematched', ($counts['bills']['checked'] ?? 0) === 0 ? 'bills' : null]));
        $status = $errors === [] ? 'succeeded' : ($reached === [] ? 'failed' : 'partial');
        $run->update([
            'status' => $status, 'counts' => $counts, 'errors' => $errors ?: null, 'finished_at' => now(),
            'duration_ms' => (int) ((hrtime(true) - $start) / 1e6),
        ]);
        $connection->update(array_filter([
            'last_sync_at' => now(),
            'status' => $status === 'failed' ? 'error' : ($connection->status === 'error' && $status === 'succeeded' ? 'connected' : null),
            'status_message' => $status === 'failed' ? $errors[0] : null,
        ], fn ($v) => $v !== null));

        return $run;
    }
}
