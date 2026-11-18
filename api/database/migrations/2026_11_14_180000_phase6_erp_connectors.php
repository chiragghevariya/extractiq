<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 6: ERP connectors.
 *  - documents get a uuid (the push idempotency key), ERP status/link and a touchless flag;
 *  - erp_connections: one row per company and connector (state, mapping, OAuth tokens);
 *  - erp_pushes becomes a log of attempts (one row each), with dead-lettering;
 *  - erp_sync_runs: history of the 15-minute pull of vendors, POs, receipts and bill status;
 *  - mock_erp_*: the in-app fake ERP used for demos without credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('erp_url')->nullable();
            $t->string('erp_status', 12)->nullable();      // draft|posted|paid|cancelled, as the ERP reports it
            $t->timestamp('erp_status_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->boolean('touchless')->default(false);     // approved and pushed without a person (auto-push rule)
        });
        foreach (DB::table('documents')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('documents')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('po_lines', function (Blueprint $t) {
            $t->string('erp_ref')->nullable();
        });

        Schema::create('erp_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('connector', 20);
            $t->boolean('is_active')->default(false);
            $t->string('status', 16)->default('unknown');   // connected|error|not_configured|unknown
            $t->text('status_message')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->jsonb('mapping')->nullable();               // fields, default account, tax codes, cost-centre rules
            $t->jsonb('settings')->nullable();              // non-secret per-connector options (mock: outage switch)
            $t->text('credentials')->nullable();            // encrypted: OAuth tokens obtained at runtime (QuickBooks)
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'connector']);
        });

        Schema::table('erp_pushes', function (Blueprint $t) {
            $t->dropUnique(['idempotency_key']);
            $t->unsignedSmallInteger('attempt')->default(1);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->boolean('retryable')->nullable();
            $t->index(['idempotency_key', 'status']);
            $t->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('erp_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('connector', 20);
            $t->string('trigger', 10);                      // schedule|manual
            $t->string('status', 10);                       // running|succeeded|partial|failed
            $t->jsonb('counts')->nullable();                // {vendors:{created,updated}, purchase_orders:…, receipts:…, bills:{posted,paid}}
            $t->jsonb('errors')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->index(['company_id', 'started_at']);
        });

        // ---- the mock ERP's own data ----------------------------------------------------------
        Schema::create('mock_erp_vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('tax_id')->nullable();
            $t->string('email')->nullable();
            $t->char('currency', 3)->default('USD');
            $t->jsonb('aliases')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('mock_erp_purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number');
            $t->string('vendor_code', 20);
            $t->date('order_date')->nullable();
            $t->char('currency', 3);
            $t->string('status', 12)->default('open');
            $t->jsonb('lines');                             // [{line_no, sku, description, quantity, uom, unit_price}]
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
        Schema::create('mock_erp_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number');
            $t->string('po_number');
            $t->date('received_date')->nullable();
            $t->jsonb('lines');                             // [{line_no, po_line_no, sku, description, quantity}]
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
        Schema::create('mock_erp_bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 30);
            $t->string('idempotency_key');
            $t->string('vendor_code', 20)->nullable();
            $t->string('vendor_name');
            $t->string('bill_number');
            $t->string('po_number')->nullable();
            $t->date('bill_date')->nullable();
            $t->date('due_date')->nullable();
            $t->char('currency', 3);
            $t->bigInteger('subtotal_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('lines');
            $t->jsonb('fields')->nullable();                // header fields as mapped
            $t->string('status', 12)->default('draft');     // draft|posted|paid|cancelled
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('attachment_name')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('attachment_disk', 20)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
            $t->unique(['company_id', 'ref']);
        });
    }

    public function down(): void
    {
        foreach (['mock_erp_bills', 'mock_erp_receipts', 'mock_erp_purchase_orders', 'mock_erp_vendors', 'erp_sync_runs', 'erp_connections'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('erp_pushes', function (Blueprint $t) {
            $t->dropIndex(['idempotency_key', 'status']);
            $t->dropIndex(['company_id', 'status', 'created_at']);
            $t->dropColumn(['attempt', 'http_status', 'duration_ms', 'retryable']);
            $t->unique('idempotency_key');
        });
        Schema::table('po_lines', fn (Blueprint $t) => $t->dropColumn('erp_ref'));
        Schema::table('documents', function (Blueprint $t) {
            $t->dropUnique(['uuid']);
            $t->dropColumn(['uuid', 'erp_url', 'erp_status', 'erp_status_at', 'paid_at', 'touchless']);
        });
    }
};
