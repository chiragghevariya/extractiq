<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\ErpPush;
use App\Models\User;
use App\Models\ErpConnection;
use App\Models\MockErp\Bill as MockBill;
use App\Services\Erp\ErpSync;
use App\Services\Erp\MockErp;
use App\Services\Matching\CsvImporter;
use App\Services\Pipeline\DocumentIngestor;
use App\Services\Pipeline\DocumentWorkflow;
use App\Services\Settings\ApprovalLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Runs every PDF in /samples through the real pipeline (synchronously), approves
 * what a reviewer would approve, then spreads timestamps over the last 14 days
 * so the dashboard has history. Deterministic: same input → same demo.
 */
class LoadDemoData extends Command
{
    protected $signature = 'extractiq:demo {--samples= : path to the samples folder} {--company=demo}';

    protected $description = 'Process the sample PDFs through the pipeline for the demo tenant';

    public function handle(DocumentIngestor $ingestor, DocumentWorkflow $workflow, CsvImporter $importer, MockErp $mock, ErpSync $sync): int
    {
        $dir = $this->option('samples') ?: base_path('../samples');
        $manifestPath = "$dir/manifest.json";
        if (! is_file($manifestPath)) {
            $this->warn("No samples manifest at $manifestPath — run `npm run generate` in /samples first.");

            return self::FAILURE;
        }
        $company = Company::where('slug', $this->option('company'))->firstOrFail();
        $reviewer = User::where('company_id', $company->id)->where('role', 'reviewer')->first();
        $admin = User::where('company_id', $company->id)->where('role', 'admin')->where('is_demo', false)->first();

        $this->clearUniqueJobLocks();

        // Run the pipeline inline and keep sockets out of it.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null', 'extractiq.pipeline.auto_approve_validated' => false]);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $docs = $manifest['documents'];

        // Master data first, the way it arrives in production: the ERP (here the Mock ERP, loaded from
        // samples/erp/seed.json) is synced into vendors, POs and receipts before any document comes in.
        if (is_file("$dir/erp/seed.json")) {
            $mock->seed($company->id, json_decode((string) file_get_contents("$dir/erp/seed.json"), true));
            ErpConnection::for($company->id, 'mock')->update(['is_active' => true, 'status' => 'connected', 'status_message' => 'Mock ERP loaded with demo data.', 'tested_at' => now()]);
            $run = $sync->run($company->id, 'manual');
            $this->line('  ERP sync: '.json_encode($run->counts));
        }
        // Vendor aliases only kept in a spreadsheet (imported once).
        if (is_file("$dir/csv/vendors.csv")) {
            $importer->import($company->id, 'vendors', (string) file_get_contents("$dir/csv/vendors.csv"));
        }

        mt_srand(2026);
        $bar = $this->output->createProgressBar(count($docs));
        $vendorEmails = ['Kestrel' => 'billing@kestrel-parts.example', 'Brightline' => 'ar@brightline-office.example', 'Harbor' => 'accounts@harbor-pine.example', 'Nimbus' => 'invoices@nimbus-cloud.example', 'Quill' => 'hello@quillfeather.example'];

        foreach ($docs as $i => $entry) {
            $path = "$dir/{$entry['file']}";
            $isScenario = str_contains($entry['file'], 'scenarios');
            $viaEmail = $entry['type'] === 'invoice' && mt_rand(0, 100) < 45;
            $vendorKey = collect(array_keys($vendorEmails))->first(fn ($k) => str_contains($entry['vendor'], $k));

            $mime = $entry['mime'] ?? (str_ends_with($path, '.png') ? 'image/png' : 'application/pdf');
            [$doc] = $ingestor->ingest($company, file_get_contents($path), basename($path), $mime, $viaEmail ? [
                'source' => 'email',
                'email_from' => $vendorEmails[$vendorKey] ?? null,
                'email_subject' => "Invoice {$entry['number']}",
            ] : ['source' => 'upload', 'user_id' => $reviewer?->id]);

            $doc->refresh();

            // Vendor memory demo: a reviewer fills in the PO Brightline prints as "Order Ref";
            // ExtractIQ learns the label and the next Brightline invoice is read correctly.
            if (str_contains($entry['file'], '16-inv-bl-7801-order-ref') && $reviewer && ! $doc->po_number) {
                $doc = $workflow->update($doc, ['po_number' => 'PO-1005'], null, $reviewer);
            }

            if ($doc->status === DocumentStatus::Validated) {
                // Above the reviewer's approval limit, an admin signs it off (as in real life).
                $approver = $reviewer && ApprovalLimits::for($company)->allows($reviewer, $doc) ? $reviewer : $admin;
                $workflow->approve($doc, $approver);
            }

            $this->backdate($doc->fresh(), $isScenario, $i);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->settleBills($company->id);
        $run = $sync->run($company->id, 'manual');
        $this->line('  ERP status sync: '.json_encode($run->counts['bills'] ?? []));
        // What the nightly job would have built for the demo's history.
        $this->call('extractiq:analytics-rollup', ['--days' => 30, '--company' => $company->slug]);

        $counts = Document::withoutGlobalScopes()->where('company_id', $company->id)
            ->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');
        $this->table(['status', 'documents'], $counts->map(fn ($c, $s) => [$s, $c])->values());

        return self::SUCCESS;
    }

    /**
     * What AP does in the ERP after bills arrive: older bills get posted, the oldest get paid.
     * The next ERP sync reads that back (pushed → posted → paid). Scenario bills stay as pushed.
     */
    private function settleBills(int $companyId): void
    {
        $docs = Document::withoutGlobalScopes()->where('company_id', $companyId)->where('status', DocumentStatus::Pushed->value)
            ->where('original_filename', 'like', 'invoice-%')->orderBy('document_date')->get();
        foreach ($docs as $i => $doc) {
            $bill = MockBill::withoutGlobalScopes()->where('company_id', $companyId)->where('ref', $doc->erp_ref)->first();
            if (! $bill || $i % 4 === 3) {
                continue; // every 4th stays as a draft in the ERP
            }
            $bill->update(['status' => $i % 2 === 0 ? 'paid' : 'posted', 'posted_at' => now(), 'paid_at' => $i % 2 === 0 ? now() : null]);
        }
    }

    /**
     * ShouldBeUnique locks outlive a migrate:fresh (ids restart at 1), which
     * would silently drop the new document's jobs. Redis keeps cache locks on
     * a separate connection, so Cache::flush() alone does not clear them.
     */
    private function clearUniqueJobLocks(): void
    {
        Cache::flush();
        if (config('cache.default') !== 'redis') {
            return;
        }
        $redis = Redis::connection(config('cache.stores.redis.lock_connection', 'default'));
        $prefix = (string) config('database.redis.options.prefix');
        foreach ($redis->keys('*laravel_unique_job*') as $key) {
            $redis->del(str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
        }
    }

    private function backdate(Document $doc, bool $scenario, int $i): void
    {
        $daysAgo = $scenario ? mt_rand(0, 1) : mt_rand(1, 13);
        $base = Carbon::now()->subDays($daysAgo)->setTime(mt_rand(8, 17), mt_rand(0, 59), mt_rand(0, 59));
        if ($base->isFuture()) {
            $base = now()->subMinutes(mt_rand(20, 180));
        }

        $t = $base->copy();
        $events = DocumentEvent::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('id')->get();
        foreach ($events as $e) {
            $human = in_array($e->step, ['approve', 'review', 'match_override'], true) || ($e->step === 'match' && $doc->type === DocumentType::Invoice);
            $t->addSeconds($human ? mt_rand(600, 7200) : max(1, (int) ceil(($e->duration_ms ?? 500) / 1000)) + mt_rand(1, 4));
            if ($t->isFuture()) {
                $t = now()->subSeconds(mt_rand(5, 60));
            }
            $e->forceFill(['created_at' => $t->copy()])->saveQuietly();
            if ($e->step === 'approve') {
                $doc->approved_at = $t->copy();
            }
            if ($e->step === 'push' && $e->status === 'succeeded') {
                $doc->pushed_at = $t->copy();
                ErpPush::withoutGlobalScopes()->where('document_id', $doc->id)
                    ->update(['created_at' => $t->copy(), 'updated_at' => $t->copy()]);
            }
        }
        $doc->received_at = $base;
        $doc->created_at = $base;
        $doc->updated_at = $t;
        $doc->saveQuietly();
    }
}
