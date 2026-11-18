<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\ErpPush;
use App\Services\Analytics\AnalyticsReport;
use App\Services\Analytics\AnalyticsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->company = demoCompany();
    $this->kestrel = mdVendor($this->company, 'Kestrel Industrial Parts LLC');
    $this->harbor = mdVendor($this->company, 'Harbor & Pine Packaging Ltd');
    $this->today = CarbonImmutable::today();
    $this->d = fn (int $daysAgo, int $hour = 9) => $this->today->subDays($daysAgo)->setTime($hour, 0);

    /**
     * An invoice that arrived $ago days back and (optionally) was approved/pushed later.
     * $fields: how many extracted fields had a value.
     */
    $this->invoice = function (array $a = []) {
        $received = $a['received'] ?? ($this->d)(5);
        $fields = [];
        for ($i = 0; $i < ($a['fields'] ?? 10); $i++) {
            $fields["f$i"] = ['has_value' => true, 'confidence' => 0.95];
        }

        return Document::factory()->type($a['type'] ?? DocumentType::Invoice)->status($a['status'] ?? DocumentStatus::Pushed)->create([
            'company_id' => $this->company->id, 'vendor_id' => ($a['vendor'] ?? $this->kestrel)->id, 'currency' => $a['currency'] ?? 'USD',
            'total_minor' => $a['total'] ?? 10000, 'received_at' => $received, 'approved_at' => array_key_exists('approved', $a) ? $a['approved'] : $received->addHours(2),
            'pushed_at' => array_key_exists('pushed', $a) ? $a['pushed'] : $received->addHours(3), 'erp_connector' => $a['connector'] ?? 'mock',
            'llm_cost_usd' => $a['llm'] ?? 0.012, 'ocr_provider' => $a['ocr'] ?? 'pdftext', 'page_count' => 1,
            'extraction' => ['fields' => $fields, 'hints_used' => $a['hints'] ?? []],
        ]);
    };
    $this->review = fn (Document $doc, array $fields, array $lines = []) => DocumentEvent::withoutGlobalScopes()->create([
        'company_id' => $doc->company_id, 'document_id' => $doc->id, 'step' => 'review', 'status' => 'succeeded', 'created_at' => now(),
        'meta' => ['diff' => ['fields' => array_fill_keys($fields, ['before' => 'x', 'after' => 'y']), 'lines' => $lines, 'accepted' => []]],
    ]);
    $this->report = fn (array $f = []) => app(AnalyticsReport::class)->build($this->company->id, $f + [
        'from' => $this->today->subDays(29), 'to' => $this->today, 'group_by' => 'day',
    ]);
});

it('computes the KPIs: processed, touchless rate, field accuracy, processing time, cost per document', function () {
    ($this->invoice)(['llm' => 0.02]);                                                       // touchless, 3 h
    $edited = ($this->invoice)(['received' => ($this->d)(4), 'pushed' => ($this->d)(4)->addHours(1), 'llm' => 0.01]); // 1 h
    ($this->review)($edited, ['total_minor', 'po_number'], [['line' => 1, 'change' => 'changed']]); // 3 corrections
    ($this->invoice)(['status' => DocumentStatus::NeedsReview, 'pushed' => null, 'approved' => null, 'received' => ($this->d)(2)]);
    ($this->invoice)(['status' => DocumentStatus::OcrDone, 'pushed' => null, 'approved' => null, 'received' => ($this->d)(1), 'ocr' => 'textract', 'type' => DocumentType::PurchaseOrder, 'llm' => 0]);

    $k = ($this->report)()['kpis'];

    expect($k['documents_received'])->toBe(4)->and($k['documents_processed'])->toBe(3) // the ocr_done one isn't done yet
        ->and($k['pushed'])->toBe(2)->and($k['touchless_rate'])->toBe(0.5)
        ->and($k['fields_total'])->toBe(20)->and($k['fields_corrected'])->toBe(3)->and($k['field_accuracy'])->toBe(0.85)
        ->and($k['avg_processing_ms'])->toBe(2 * 3_600_000)
        ->and($k['llm_cost_usd'])->toEqual(0.042)->and($k['ocr_cost_usd'])->toEqual(0.065) // one Textract AnalyzeDocument page
        ->and($k['cost_per_document_usd'])->toEqual(round((0.042 + 0.065) / 4, 6));
});

it('does not count reviewer-free documents as edited, but does count accepted variances and vendor picks', function () {
    $a = ($this->invoice)();
    $b = ($this->invoice)();
    $c = ($this->invoice)();
    foreach ([[$b, 'match_accept'], [$c, 'vendor']] as [$doc, $step]) {
        DocumentEvent::withoutGlobalScopes()->create(['company_id' => $doc->company_id, 'document_id' => $doc->id, 'step' => $step, 'status' => 'succeeded', 'created_at' => now()]);
    }
    // A review event that changed nothing (approve with no edits) doesn't count.
    ($this->review)($a, []);

    expect(($this->report)()['kpis']['touchless_rate'])->toBe(round(1 / 3, 4));
});

it('builds the volume series by status group, and groups by week or month', function () {
    ($this->invoice)(['received' => ($this->d)(3)]);
    ($this->invoice)(['received' => ($this->d)(3), 'status' => DocumentStatus::MatchException, 'pushed' => null]);
    ($this->invoice)(['received' => ($this->d)(3), 'status' => DocumentStatus::NeedsReview, 'pushed' => null, 'approved' => null]);
    ($this->invoice)(['received' => ($this->d)(3), 'status' => DocumentStatus::Approved, 'pushed' => null]);

    $day = collect(($this->report)()['series'])->firstWhere('period', ($this->d)(3)->toDateString());
    expect($day)->toMatchArray(['received' => 4, 'in_erp' => 1, 'stopped' => 1, 'in_review' => 1, 'in_progress' => 1])
        ->and(($this->report)()['series'])->toHaveCount(30);

    $weekly = ($this->report)(['group_by' => 'week'])['series'];
    expect(collect($weekly)->pluck('period')->every(fn ($p) => CarbonImmutable::parse($p)->isMonday()))->toBeTrue()
        ->and(collect($weekly)->sum('received'))->toBe(4);
    expect(collect(($this->report)(['group_by' => 'month'])['series'])->sum('received'))->toBe(4);
});

it('reports the processing-time distribution, top vendors per currency, exception reasons and ERP success', function () {
    ($this->invoice)(['pushed' => ($this->d)(5)->addSeconds(30)]);                        // under a minute
    ($this->invoice)(['total' => 50000]);                                                  // 3 h, Kestrel
    ($this->invoice)(['vendor' => $this->harbor, 'total' => 20000]);
    ($this->invoice)(['vendor' => $this->harbor, 'total' => 9000, 'currency' => 'EUR']);
    $exc = ($this->invoice)(['status' => DocumentStatus::MatchException, 'pushed' => null]);
    fakeMatch($exc, ['header_issues' => [['code' => 'po_not_found', 'message' => 'x']], 'created_at' => ($this->d)(3)]);
    foreach (['succeeded', 'succeeded', 'failed', 'dead'] as $s) {
        ErpPush::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'document_id' => $exc->id, 'connector' => 'mock', 'idempotency_key' => 'k', 'status' => $s, 'created_at' => ($this->d)(2)]);
    }

    $r = ($this->report)();
    $dist = collect($r['processing_distribution'])->pluck('count', 'bucket');
    expect($dist['lt_1m'])->toBe(1)->and($dist['2h_8h'])->toBe(3)->and($r['processing_distribution'])->toHaveCount(8);

    expect($r['top_vendors'][0]['currency'])->toBe('USD')
        ->and($r['top_vendors'][0]['vendors'][0])->toMatchArray(['name' => 'Kestrel Industrial Parts LLC', 'spend_minor' => 70000]) // 2 pushed + the exception (approved)
        ->and($r['top_vendors'][1])->toMatchArray(['currency' => 'EUR', 'total_minor' => 9000]);
    expect($r['exception_reasons'])->toBe([['code' => 'po_not_found', 'count' => 1]]);
    expect($r['erp'])->toMatchArray(['attempts' => 4, 'succeeded' => 2, 'dead' => 1, 'retried' => 1, 'success_rate' => round(2 / 3, 4)]);
});

it('shows accuracy per week, separately for documents read with learned vendor hints', function () {
    $before = ($this->invoice)(['approved' => ($this->d)(15)]);
    ($this->review)($before, ['po_number', 'document_number']);           // 2 of 10 corrected before the hint existed
    ($this->invoice)(['approved' => ($this->d)(2), 'hints' => [['field' => 'po_number', 'hint' => 'Order Ref']]]); // 0 of 10 after

    $weeks = collect(($this->report)()['accuracy_trend'])->filter(fn ($w) => $w['fields_total'] > 0)->values();
    expect($weeks->first()['accuracy'])->toBe(0.8)->and($weeks->first()['hinted_accuracy'])->toBeNull()
        ->and($weeks->last()['accuracy'])->toEqual(1)->and($weeks->last()['hinted_accuracy'])->toEqual(1)->and($weeks->last()['hinted_docs'])->toBe(1);
});

it('compares with the previous period of the same length', function () {
    ($this->invoice)(['received' => ($this->d)(40)]);
    ($this->invoice)(['received' => ($this->d)(5)]);
    ($this->invoice)(['received' => ($this->d)(4)]);

    $k = ($this->report)()['kpis'];
    expect($k['documents_received'])->toBe(2)->and($k['previous']['documents_received'])->toBe(1);
});

it('stores past days in the nightly rollup, backfills missing days, and computes today live', function () {
    ($this->invoice)(['received' => ($this->d)(3)]);
    $this->artisan('extractiq:analytics-rollup --days=7')->assertSuccessful();
    expect(DB::table('analytics_rollup_days')->where('company_id', $this->company->id)->count())->toBe(7)
        ->and(DB::table('analytics_daily')->where('company_id', $this->company->id)->sum('received'))->toEqual(1);

    // Days never rolled up (older than the nightly window) are built on demand…
    ($this->invoice)(['received' => ($this->d)(20)]);
    // …and a document arriving today shows up without waiting for the night.
    ($this->invoice)(['received' => $this->today->setTime(8, 0), 'pushed' => null, 'approved' => null, 'status' => DocumentStatus::NeedsReview]);
    expect(($this->report)()['kpis']['documents_received'])->toBe(3)
        ->and(DB::table('analytics_rollup_days')->where('company_id', $this->company->id)->count())->toBe(59) // every past day in range + the previous period
        ->and(DB::table('analytics_daily')->where('company_id', $this->company->id)->where('day', $this->today->toDateString())->count())->toBe(0); // today stays live

    // A past day already rolled up is rebuilt by the nightly job, not on every request.
    ($this->invoice)(['received' => ($this->d)(3)]);
    expect(($this->report)()['kpis']['documents_received'])->toBe(3);
    $this->artisan('extractiq:analytics-rollup --days=7')->assertSuccessful();
    expect(($this->report)()['kpis']['documents_received'])->toBe(4);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'extractiq:analytics-rollup'));
    expect($event->expression)->toBe('15 1 * * *');
});

describe('API', function () {
    beforeEach(fn () => Sanctum::actingAs(userWithRole('viewer', $this->company)));

    it('serves GET /analytics with filters for vendor, type and connector', function () {
        ($this->invoice)();
        ($this->invoice)(['vendor' => $this->harbor, 'connector' => 'odoo']);
        ($this->invoice)(['type' => DocumentType::PurchaseOrder, 'pushed' => null, 'status' => DocumentStatus::Approved, 'connector' => null]);

        $this->getJson('/api/v1/analytics')->assertOk()
            ->assertJsonPath('data.kpis.documents_received', 3)->assertJsonPath('data.period.group_by', 'day')->assertJsonPath('data.period.days', 30)
            ->assertJsonCount(4, 'meta.options.connectors')->assertJsonPath('meta.options.vendors.0.name', 'Harbor & Pine Packaging Ltd');
        $this->getJson("/api/v1/analytics?vendor={$this->harbor->id}")->assertJsonPath('data.kpis.documents_received', 1);
        $this->getJson('/api/v1/analytics?type=purchase_order')->assertJsonPath('data.kpis.documents_received', 1)->assertJsonPath('data.kpis.pushed', 0);
        $this->getJson('/api/v1/analytics?connector=odoo')->assertJsonPath('data.kpis.pushed', 1);
        $from = $this->today->subDays(89)->toDateString();
        $this->getJson("/api/v1/analytics?from=$from&to={$this->today->toDateString()}")->assertJsonPath('data.period.group_by', 'week');
        $this->getJson("/api/v1/analytics?from=$from&group_by=month")->assertJsonPath('data.period.group_by', 'month');
    });

    it('validates the range and the filters', function () {
        $this->getJson('/api/v1/analytics?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->getJson('/api/v1/analytics?from=2024-01-01&to=2026-01-01')->assertStatus(422)->assertJsonPath('errors.0.code', 'range_too_long');
        $this->getJson('/api/v1/analytics?group_by=hour')->assertStatus(422);
        $other = mdVendor(Company::factory()->create(), 'Elsewhere');
        $this->getJson("/api/v1/analytics?vendor={$other->id}")->assertStatus(422);
    });

    it('exports the series as CSV', function () {
        ($this->invoice)(['received' => ($this->d)(3)]);
        $res = $this->get('/api/v1/analytics/export?group_by=week')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $lines = array_values(array_filter(explode("\n", (string) $res->getContent())));

        expect($lines[0])->toStartWith('period,received,processed,in_erp,in_progress,in_review,stopped,pushed,touchless,touchless_rate')
            ->and(count($lines))->toBeGreaterThan(1)
            ->and($res->headers->get('content-disposition'))->toContain('-by-week.csv');
    });

    it('includes pipeline health: stages, failed jobs, ERP last 24 h and connectors', function () {
        Document::factory()->status(DocumentStatus::Received)->create(['company_id' => $this->company->id]);
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'redis', 'queue' => 'pipeline', 'payload' => json_encode(['displayName' => 'App\\Jobs\\RunOcr']), 'exception' => 'x', 'failed_at' => now()]);

        $h = $this->getJson('/api/v1/analytics')->assertOk()->json('data.health');
        expect(collect($h['stages'])->firstWhere('status', 'received')['waiting'])->toBe(1)
            ->and($h['failed_jobs'])->toMatchArray(['last_24h' => 1, 'by_job' => ['RunOcr' => 1]])
            ->and($h['queues']['queues'])->toBeNull() // tests run on the sync queue
            ->and(collect($h['connectors'])->pluck('connector')->all())->toBe(['mock', 'odoo', 'quickbooks', 'webhook']);
    });

    it('keeps each company to its own numbers', function () {
        $other = Company::factory()->create();
        Document::factory()->create(['company_id' => $other->id, 'received_at' => now()]);
        ($this->invoice)();
        $this->getJson('/api/v1/analytics')->assertJsonPath('data.kpis.documents_received', 1);
        expect(app(AnalyticsRollup::class)->compute($other->id, $this->today, $this->today))->toHaveCount(1);
    });
});
