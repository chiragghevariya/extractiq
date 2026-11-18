<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\User;
use App\Services\Pipeline\DocumentIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

function samplePath(string $file): string
{
    $matches = glob(base_path('../samples/pdf/*/'.$file));

    return $matches[0] ?? throw new RuntimeException("Sample $file not found — run npm run generate in /samples.");
}

function sampleText(string $file): string
{
    return (new Parser)->parseFile(samplePath($file))->getText();
}

function demoCompany(): Company
{
    return Company::factory()->create(['slug' => 'demo', 'inbound_email' => 'ap-demo@inbound.extractiq.test']);
}

function userWithRole(string $role = 'reviewer', ?Company $company = null): User
{
    return User::factory()->role($role)->create(['company_id' => ($company ?? demoCompany())->id]);
}

/** Ingest a sample PDF through the real (sync) pipeline. */
function ingestSample(Company $company, string $file): Document
{
    [$doc] = app(DocumentIngestor::class)->ingest($company, file_get_contents(samplePath($file)), $file, 'application/pdf');

    return $doc->fresh();
}

/** A document at ocr_done whose pages carry the stored OCR of an extraction fixture (tests/Fixtures/extraction/{name}). */
function fixtureDocument(Company $company, string $name, string $type = 'invoice', array $attrs = []): Document
{
    $fx = json_decode(file_get_contents(base_path("tests/Fixtures/extraction/$name/ocr.json")), true);
    $path = "{$company->slug}/fixtures/$name.".($fx['mime'] === 'application/pdf' ? 'pdf' : 'png');
    $sample = glob(base_path('../samples/pdf/*/'.$fx['source']))[0] ?? null;
    Storage::disk('local')->put($path, $sample ? file_get_contents($sample) : 'x');

    $doc = Document::factory()->create($attrs + [
        'company_id' => $company->id, 'status' => DocumentStatus::OcrDone, 'type' => $type,
        'mime_type' => $fx['mime'], 'storage_path' => $path, 'ocr_text' => $fx['ocr_text'], 'original_filename' => $fx['source'],
        'vendor_name' => null, 'document_number' => null, 'document_date' => null, 'due_date' => null, 'total_minor' => null,
        'subtotal_minor' => null, 'tax_minor' => null, 'currency' => null, 'confidence' => null,
    ]);
    foreach ($fx['pages'] as $p) {
        DocumentPage::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'document_id' => $doc->id, 'page_number' => $p['page_number'],
            'text' => $p['text'], 'ocr' => $p['ocr'], 'ocr_provider' => $p['provider'], 'char_count' => mb_strlen($p['text']),
        ]);
    }

    return $doc;
}

function fixtureReply(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/extraction/$name/llm_reply.json")), true);
}

/** A stored match row with sensible defaults (for API tests that don't run the engine). */
function fakeMatch(App\Models\Document $invoice, array $attrs = []): App\Models\DocumentMatch
{
    return App\Models\DocumentMatch::withoutGlobalScopes()->create($attrs + [
        'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'kind' => 'two_way', 'result' => 'exception',
        'header_issues' => [['code' => 'po_not_found', 'message' => 'No PO.']], 'discrepancies' => [['code' => 'po_not_found', 'message' => 'No PO.']],
        'totals' => ['invoiced_minor' => $invoice->total_minor ?? 0, 'expected_minor' => 0, 'delta_minor' => 0, 'disputed_minor' => 0],
        'goods_receipt_ids' => [], 'tolerances' => [],
    ]);
}

/**
 * Matching fixtures. Lines are [sku, qty, unit_price_minor, description?].
 */
function mdVendor(App\Models\Company $c, string $name, array $attrs = []): App\Models\Vendor
{
    return App\Models\Vendor::withoutGlobalScopes()->create(['company_id' => $c->id, 'name' => $name] + $attrs);
}

function mdPo(App\Models\Company $c, string $number, array $lines, array $header = [], string $source = 'csv'): App\Models\PurchaseOrder
{
    return app(App\Services\Matching\MasterData::class)->upsertPurchaseOrder($c->id, $header + ['po_number' => $number, 'currency' => 'USD'],
        array_map(fn ($l) => ['sku' => $l[0], 'quantity' => $l[1], 'unit_price_minor' => $l[2], 'description' => $l[3] ?? "Item {$l[0]}"], $lines), $source);
}

/** Receipt lines are [sku, qty, description?]. */
function mdGrn(App\Models\Company $c, string $number, string $poNumber, array $lines): App\Models\GoodsReceipt
{
    return app(App\Services\Matching\MasterData::class)->upsertGoodsReceipt($c->id, ['grn_number' => $number, 'po_number' => $poNumber],
        array_map(fn ($l) => ['sku' => $l[0], 'quantity' => $l[1], 'description' => $l[2] ?? "Item {$l[0]}"], $lines), 'csv');
}

function mdInvoice(App\Models\Company $c, array $lines, array $attrs = []): App\Models\Document
{
    $doc = App\Models\Document::factory()->type(App\Enums\DocumentType::Invoice)->status(App\Enums\DocumentStatus::Approved)
        ->create($attrs + ['company_id' => $c->id, 'currency' => 'USD', 'po_number' => 'PO-9',
            'subtotal_minor' => (int) collect($lines)->sum(fn ($l) => round($l[1] * $l[2])), 'tax_minor' => 0, 'shipping_minor' => 0]);
    foreach ($lines as $i => $l) {
        App\Models\DocumentLine::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'document_id' => $doc->id, 'line_no' => $i + 1, 'sku' => $l[0], 'description' => $l[3] ?? "Item {$l[0]}",
            'quantity' => $l[1], 'unit_price_minor' => $l[2], 'amount_minor' => (int) round($l[1] * $l[2]),
        ]);
    }

    return $doc;
}

/** Run the engine and, like the job does, move the invoice to matched / match_exception. */
function mdMatch(App\Models\Document $invoice): App\Models\DocumentMatch
{
    $m = app(App\Services\Matching\MatchingEngine::class)->match($invoice->fresh());
    $invoice->refresh()->transitionTo($m->result === 'matched' ? App\Enums\DocumentStatus::Matched : App\Enums\DocumentStatus::MatchException);

    return $m;
}

/** @return list<string> per line result, in line order */
function mdResults(App\Models\DocumentMatch $m): array
{
    return $m->lines->sortBy('line_no')->pluck('result')->values()->all();
}

/**
 * A fake Odoo JSON-RPC server. $routes maps "model.method" (or "common.login" / "common.version") to a
 * result or fn(array $args, array $kwargs) (which may throw: returned as an Odoo error).
 * Note: call it once per test; a second Http::fake doesn't replace the first. Every call is appended to the returned ArrayObject as
 * ['call' => 'model.method', 'args' => [...], 'kwargs' => [...]]. Unrouted calls return [] / false.
 */
function fakeOdoo(array $routes): ArrayObject
{
    $calls = new ArrayObject;
    Illuminate\Support\Facades\Http::fake(function (Illuminate\Http\Client\Request $req) use ($routes, $calls) {
        $p = $req['params'];
        if ($p['service'] === 'common') {
            $key = "common.{$p['method']}";
            $args = $p['args'];
            $kwargs = [];
        } else {
            $key = $p['args'][3].'.'.$p['args'][4];
            $args = $p['args'][5];
            $kwargs = (array) ($p['args'][6] ?? []);
        }
        $calls->append(['call' => $key, 'args' => $args, 'kwargs' => $kwargs]);
        $route = $routes[$key] ?? match ($key) {
            'common.login' => 7,
            'common.version' => ['server_version' => '17.0'],
            default => [],
        };
        try {
            if ($route instanceof Throwable) {
                throw $route;
            }
            $result = $route instanceof Closure ? $route($args, $kwargs) : $route;
        } catch (Throwable $e) {
            // Odoo reports server-side errors as a JSON-RPC error on HTTP 200.
            return Illuminate\Support\Facades\Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'Odoo Server Error', 'data' => ['message' => $e->getMessage()]]]);
        }

        return Illuminate\Support\Facades\Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    });

    return $calls;
}

/** Calls of one kind from fakeOdoo()'s log. */
function odooCalls(ArrayObject $calls, string $key): array
{
    return array_values(array_filter($calls->getArrayCopy(), fn ($c) => $c['call'] === $key));
}

/** A matched invoice (PO-matched, ready to push) with its original file in storage. */
function pushableInvoice(App\Models\Company $c, array $attrs = []): App\Models\Document
{
    Illuminate\Support\Facades\Storage::fake('local');
    $doc = App\Models\Document::factory()->type(App\Enums\DocumentType::Invoice)->status(App\Enums\DocumentStatus::Matched)
        ->withLines([['A-1', 10, 1000], ['B-2', 5, 2000]])
        ->create($attrs + ['company_id' => $c->id, 'document_number' => 'INV-77', 'po_number' => 'PO-9', 'currency' => 'USD',
            'subtotal_minor' => 20000, 'tax_minor' => 1600, 'total_minor' => 21600, 'document_date' => '2026-11-01', 'due_date' => '2026-12-01',
            'storage_disk' => 'local', 'storage_path' => 'docs/inv-77.pdf', 'original_filename' => 'inv-77.pdf', 'mime_type' => 'application/pdf']);
    Illuminate\Support\Facades\Storage::disk('local')->put('docs/inv-77.pdf', '%PDF-1.4 fake');

    return $doc;
}

/** Build a bill for a document with the company's current mapping for a connector. */
function billFor(App\Models\Document $doc, string $connector): App\Services\Erp\ErpBill
{
    return App\Services\Erp\ErpBill::fromDocument($doc->fresh(), App\Services\Erp\ErpMapping::for(App\Models\ErpConnection::for($doc->company_id, $connector)));
}
