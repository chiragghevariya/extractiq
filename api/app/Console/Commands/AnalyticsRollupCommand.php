<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Analytics\AnalyticsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Nightly: rebuild analytics_daily for the last N days (default 30). Recent days are recomputed
 * because a document's status keeps moving after it arrives (review, matching, ERP payment).
 */
class AnalyticsRollupCommand extends Command
{
    protected $signature = 'extractiq:analytics-rollup {--days=30 : how many days back, ending yesterday} {--company= : company slug}';

    protected $description = 'Rebuild the analytics daily rollup';

    public function handle(AnalyticsRollup $rollup): int
    {
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(max(1, (int) $this->option('days')) - 1);
        foreach (Company::query()->when($this->option('company'), fn ($q, $s) => $q->where('slug', $s))->get() as $company) {
            $rows = $rollup->store($company->id, $from, $to);
            $this->line("{$company->slug}: {$from->toDateString()} → {$to->toDateString()}, $rows rows");
        }

        return self::SUCCESS;
    }
}
