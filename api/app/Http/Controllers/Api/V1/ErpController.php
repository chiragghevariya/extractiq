<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncErp;
use App\Models\Document;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\MockErp;
use App\Models\Vendor;
use App\Services\Erp\ErpException;
use App\Services\Erp\ErpManager;
use App\Services\Erp\ErpMapping;
use App\Services\Erp\QuickBooksOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** ERP connections: state, test, activate, mapping, sync, and the QuickBooks OAuth flow. */
class ErpController extends Controller
{
    public const LABELS = ['mock' => 'Mock ERP', 'odoo' => 'Odoo 17', 'quickbooks' => 'QuickBooks Online', 'webhook' => 'Webhook'];

    public function __construct(private readonly ErpManager $erp) {}

    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        $active = ErpConnection::activeFor($companyId)->connector;

        return response()->json([
            'data' => collect(ErpConnection::CONNECTORS)->map(fn ($c) => $this->resource(ErpConnection::for($companyId, $c), $c === $active))->values(),
            'meta' => [
                'active' => $active,
                'last_run' => ($run = ErpSyncRun::query()->latest('started_at')->first()) ? $this->run($run) : null,
                'sync_every_minutes' => config('extractiq.erp.sync_every_minutes'),
                'mapping_fields' => ErpMapping::SOURCE_FIELDS,
            ],
        ]);
    }

    public function test(Request $request, string $connector)
    {
        $connection = $this->connection($request, $connector);
        $result = $this->erp->forCompany($connection->company_id, $connector)->testConnection();
        $connection->update(['status' => $result['ok'] ? 'connected' : 'error', 'status_message' => $result['message'], 'tested_at' => now()]);

        return response()->json(['data' => $this->resource($connection->fresh(), $connection->is_active) + ['ok' => $result['ok'], 'message' => $result['message']]]);
    }

    /** Make this connector the one invoices are pushed to (and synced from). */
    public function activate(Request $request, string $connector)
    {
        $connection = $this->connection($request, $connector);
        ErpConnection::query()->where('id', '!=', $connection->id)->update(['is_active' => false]);
        $connection->update(['is_active' => true]);

        return $this->index($request);
    }

    public function mapping(Request $request, string $connector)
    {
        $connection = $this->connection($request, $connector);
        $targets = ErpMapping::TARGETS[$connector] ?? null;
        $data = $request->validate([
            'fields' => 'sometimes|array',
            'fields.*' => ['nullable', 'string', 'max:60', $targets === null ? 'regex:/^[A-Za-z_][\w.]*$/' : 'regex:/^[\w.]*$/'],
            'default_account' => 'nullable|string|max:60',
            'tax_codes' => 'sometimes|array|max:50',
            'tax_codes.*.rate' => 'required|numeric|min:0|max:100',
            'tax_codes.*.code' => 'required|string|max:60',
            'cost_centres' => 'sometimes|array|max:500',
            'cost_centres.*.vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('company_id', $request->user()->company_id)],
            'cost_centres.*.cost_centre' => 'nullable|string|max:60',
            'cost_centres.*.account' => 'nullable|string|max:60',
        ]);
        foreach ($data['fields'] ?? [] as $ours => $theirs) {
            if (! array_key_exists($ours, ErpMapping::SOURCE_FIELDS)) {
                throw ValidationException::withMessages(["fields.$ours" => "Unknown field \"$ours\"."]);
            }
            if ($targets !== null && ! in_array((string) $theirs, $targets[$ours], true)) {
                throw ValidationException::withMessages(["fields.$ours" => self::LABELS[$connector]." can't take ".ErpMapping::SOURCE_FIELDS[$ours].' in "'.$theirs.'". Choose one of: '.implode(', ', array_filter($targets[$ours])).'.']);
            }
        }
        $current = ErpMapping::for($connection)->toArray();
        $connection->update(['mapping' => [
            'fields' => array_map(fn ($v) => (string) $v, ($data['fields'] ?? []) + $current['fields']),
            'default_account' => array_key_exists('default_account', $data) ? $data['default_account'] : $current['default_account'],
            'tax_codes' => array_values($data['tax_codes'] ?? $current['tax_codes']),
            'cost_centres' => array_values($data['cost_centres'] ?? $current['cost_centres']),
        ]]);

        return response()->json(['data' => $this->resource($connection->fresh(), $connection->is_active)]);
    }

    /** "Sync now": queue the same pull the scheduler runs every 15 minutes. */
    public function sync(Request $request)
    {
        SyncErp::dispatch($request->user()->company_id, 'manual');

        return response()->json(['meta' => ['queued' => true, 'last_run' => ($r = ErpSyncRun::query()->latest('started_at')->first()) ? $this->run($r) : null]], 202);
    }

    public function runs()
    {
        return response()->json(['data' => ErpSyncRun::query()->latest('started_at')->limit(20)->get()->map(fn ($r) => $this->run($r))]);
    }

    public function settings(Request $request, string $connector)
    {
        $connection = $this->connection($request, $connector);
        $data = $request->validate(['outage' => 'sometimes|boolean']);
        abort_unless($connector === 'mock', 404);
        $connection->update(['settings' => $data + ($connection->settings ?? [])]);

        return response()->json(['data' => $this->resource($connection->fresh(), $connection->is_active)]);
    }

    // ---- QuickBooks OAuth 2.0 --------------------------------------------------------------------

    public function quickbooksConnect(Request $request, QuickBooksOAuth $oauth)
    {
        return response()->json(['data' => ['url' => $oauth->authorizeUrl($request->user()->company_id, $request->user()->id)]]);
    }

    /** Intuit redirects the browser here; we store the tokens and send the user back to the ERP Sync page. */
    public function quickbooksCallback(Request $request, QuickBooksOAuth $oauth)
    {
        $web = rtrim((string) config('extractiq.web_url'), '/').'/erp-sync';
        if ($request->filled('error')) {
            return redirect($web.'?qbo=error&message='.urlencode((string) $request->input('error_description', $request->input('error'))));
        }
        $data = $request->validate(['code' => 'required|string', 'state' => 'required|string', 'realmId' => 'required|string']);
        try {
            $oauth->complete($data['state'], $data['code'], $data['realmId']);
        } catch (ErpException $e) {
            return redirect($web.'?qbo=error&message='.urlencode($e->getMessage()));
        }

        return redirect($web.'?qbo=connected');
    }

    public function quickbooksDisconnect(Request $request, QuickBooksOAuth $oauth)
    {
        $oauth->disconnect($this->connection($request, 'quickbooks'));

        return $this->index($request);
    }

    /** The original file behind a signed, expiring link (sent in webhook payloads). */
    public function file(string $uuid)
    {
        $doc = Document::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        return Storage::disk($doc->storage_disk)->response($doc->storage_path, $doc->original_filename, ['Content-Type' => $doc->mime_type]);
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function connection(Request $request, string $connector): ErpConnection
    {
        abort_unless(in_array($connector, ErpConnection::CONNECTORS, true), 404);

        return ErpConnection::for($request->user()->company_id, $connector);
    }

    private function resource(ErpConnection $c, bool $active): array
    {
        $cfg = (array) config("extractiq.erp.drivers.{$c->connector}", []);
        $creds = $c->credentials ?? [];
        [$configured, $summary] = match ($c->connector) {
            'mock' => [true, [
                'outage' => (bool) ($c->settings['outage'] ?? false),
                'bills' => MockErp\Bill::query()->count(), 'purchase_orders' => MockErp\PurchaseOrder::query()->count(), 'vendors' => MockErp\Vendor::query()->count(),
            ]],
            'odoo' => [filled($cfg['password'] ?? null), ['url' => $cfg['url'] ?? null, 'database' => $cfg['database'] ?? null, 'username' => $cfg['username'] ?? null]],
            'quickbooks' => [filled($creds['access_token'] ?? null), [
                'environment' => $cfg['environment'] ?? 'sandbox', 'realm_id' => $creds['realm_id'] ?? null,
                'app_configured' => app(QuickBooksOAuth::class)->configured(), 'token_expires_at' => $creds['refresh_expires_at'] ?? null,
            ]],
            'webhook' => [filled($cfg['url'] ?? null), ['host' => isset($cfg['url']) ? parse_url((string) $cfg['url'], PHP_URL_HOST) : null, 'signed' => filled($cfg['secret'] ?? null)]],
            default => [false, []],
        };

        return [
            'connector' => $c->connector,
            'label' => self::LABELS[$c->connector] ?? $c->connector,
            'is_active' => $active,
            'configured' => $configured,
            'status' => $configured ? $c->status : 'not_configured',
            'status_message' => $c->status_message,
            'tested_at' => $c->tested_at?->toIso8601String(),
            'last_sync_at' => $c->last_sync_at?->toIso8601String(),
            'capabilities' => $this->erp->make($c->connector, new \App\Services\Erp\ErpContext($c->company_id, $c))->capabilities(),
            'summary' => $summary,
            'mapping' => ErpMapping::for($c)->toArray(),
            'mapping_targets' => ErpMapping::TARGETS[$c->connector] ?? null,
            'vendors' => array_values(array_filter(array_map(fn ($r) => ($v = Vendor::find($r['vendor_id'])) ? ['id' => (string) $v->id, 'name' => $v->name] : null, ErpMapping::for($c)->costCentres))),
        ];
    }

    private function run(ErpSyncRun $r): array
    {
        return [
            'id' => (string) $r->id, 'connector' => $r->connector, 'trigger' => $r->trigger, 'status' => $r->status, 'counts' => $r->counts, 'errors' => $r->errors,
            'duration_ms' => $r->duration_ms, 'started_at' => $r->started_at->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
