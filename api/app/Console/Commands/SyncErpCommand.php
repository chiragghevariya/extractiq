<?php

namespace App\Console\Commands;

use App\Jobs\SyncErp;
use App\Models\Company;
use App\Services\Erp\ErpSync;
use Illuminate\Console\Command;

/** Scheduled every 15 minutes: queue an ERP pull for every company (or run one inline with --now). */
class SyncErpCommand extends Command
{
    protected $signature = 'extractiq:erp-sync {--company= : company slug} {--now : run inline instead of queueing}';

    protected $description = 'Pull vendors, purchase orders, receipts and bill payment status from each company\'s ERP';

    public function handle(ErpSync $sync): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        foreach ($companies as $company) {
            if ($this->option('now')) {
                $run = $sync->run($company->id, 'manual');
                $this->line(sprintf('%s · %s: %s %s%s', $company->slug, $run->connector, $run->status, json_encode($run->counts), $run->errors ? ' — '.implode('; ', $run->errors) : ''));
            } else {
                SyncErp::dispatch($company->id);
            }
        }
        if (! $this->option('now')) {
            $this->info("Queued ERP sync for {$companies->count()} compan".($companies->count() === 1 ? 'y' : 'ies').'.');
        }

        return self::SUCCESS;
    }
}
