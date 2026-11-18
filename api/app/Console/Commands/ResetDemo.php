<?php

namespace App\Console\Commands;

use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly reset of the public demo: drop the demo tenant (every business table cascades on
 * company_id) and its files, then seed it again through the real pipeline. Other tenants are
 * untouched. Only runs with DEMO_MODE=true unless forced.
 */
class ResetDemo extends Command
{
    protected $signature = 'extractiq:demo-reset {--force : run even when DEMO_MODE is off}';

    protected $description = 'Wipe and re-seed the demo tenant (public demo nightly reset)';

    public function handle(): int
    {
        if (! config('extractiq.demo.enabled') && ! $this->option('force')) {
            $this->warn('DEMO_MODE is off; nothing reset. Use --force to reset anyway.');

            return self::SUCCESS;
        }
        $slug = (string) config('extractiq.demo.company');
        $started = microtime(true);

        if ($company = Company::where('slug', $slug)->first()) {
            foreach (array_unique([config('extractiq.documents_disk'), 'local']) as $disk) {
                rescue(fn () => Storage::disk($disk)->deleteDirectory($slug), report: false);
            }
            $company->delete(); // cascades to documents, events, master data, ERP rows, users…
            $this->line("Removed tenant '$slug'.");
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->info(sprintf('Demo reset in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
