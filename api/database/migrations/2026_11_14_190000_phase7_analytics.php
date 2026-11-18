<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: a daily rollup for analytics. One row per company × day × vendor × document type ×
 * connector, holding additive measures (counts, sums, small JSON histograms), so any date range
 * and filter combination is a cheap SUM over rows. Rebuilt nightly for recent days (statuses keep
 * moving after a document arrives); today is always computed live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedBigInteger('vendor_id')->nullable();   // no FK: a deleted vendor must not rewrite history
            $t->string('type', 20);
            $t->string('connector', 20)->nullable();
            // by received_at day
            $t->unsignedInteger('received')->default(0);
            $t->unsignedInteger('processed')->default(0);        // finished OCR + extraction
            $t->jsonb('status_counts')->nullable();               // current status of the day's arrivals
            $t->decimal('llm_cost_usd', 12, 6)->default(0);
            $t->decimal('ocr_cost_usd', 12, 6)->default(0);
            // by pushed_at day
            $t->unsignedInteger('pushed')->default(0);
            $t->unsignedInteger('touchless')->default(0);          // pushed with no human edit
            $t->unsignedBigInteger('processing_ms_sum')->default(0); // received -> pushed
            $t->jsonb('processing_hist')->nullable();
            // by approved_at day (a person or the touchless rule signed the values off)
            $t->unsignedInteger('fields_total')->default(0);
            $t->unsignedInteger('fields_corrected')->default(0);
            $t->unsignedInteger('hinted_docs')->default(0);         // extracted with learned vendor hints
            $t->unsignedInteger('hinted_fields_total')->default(0);
            $t->unsignedInteger('hinted_fields_corrected')->default(0);
            $t->jsonb('spend')->nullable();                          // approved invoices: {currency: total_minor}
            // by match created_at day
            $t->jsonb('exception_reasons')->nullable();              // {code: count}
            // by push attempt day
            $t->unsignedInteger('erp_attempts')->default(0);
            $t->unsignedInteger('erp_succeeded')->default(0);
            $t->unsignedInteger('erp_dead')->default(0);
            $t->index(['company_id', 'day']);
        });

        Schema::create('analytics_rollup_days', function (Blueprint $t) {
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->timestamp('computed_at');
            $t->primary(['company_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_rollup_days');
        Schema::dropIfExists('analytics_daily');
    }
};
