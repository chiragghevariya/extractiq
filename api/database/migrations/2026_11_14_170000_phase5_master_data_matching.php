<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: master data (vendors with aliases, purchase orders, goods receipts) and the
 * line-level matching engine. document_matches is rebuilt because its PO / GRN keys now
 * point at the master tables instead of at documents; matches are derived data and are
 * recomputed by re-running matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        if ($pg) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }

        Schema::table('vendors', function (Blueprint $t) {
            $t->jsonb('aliases')->nullable();          // as typed: ["Kestrel Parts", "KIP"]
            $t->jsonb('alias_keys')->nullable();       // normalised, for lookups
            $t->string('tax_key', 40)->nullable();     // tax id, upper-case alphanumerics only
            $t->string('source', 10)->default('manual'); // manual|csv|erp|document
            $t->index(['company_id', 'tax_key']);
        });
        // Names are now normalised a little more ("L.L.C." = "LLC", "&" = "and"); refresh existing rows.
        foreach (DB::table('vendors')->get(['id', 'name', 'tax_id']) as $v) {
            DB::table('vendors')->where('id', $v->id)->update(['normalized_name' => \App\Models\Vendor::normalize($v->name), 'tax_key' => \App\Models\Vendor::taxKey($v->tax_id)]);
        }
        if ($pg) {
            DB::statement('CREATE INDEX vendors_normalized_name_trgm ON vendors USING gin (normalized_name gin_trgm_ops)');
        }

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('po_number');
            $t->string('number_key', 60);              // upper-case alphanumerics: "PO-1001" -> "PO1001"
            $t->date('order_date')->nullable();
            $t->date('expected_date')->nullable();
            $t->char('currency', 3)->nullable();
            $t->bigInteger('subtotal_minor')->nullable();
            $t->bigInteger('tax_minor')->nullable();
            $t->bigInteger('total_minor')->nullable();
            $t->string('status', 12)->default('open'); // open|closed|cancelled
            $t->string('source', 10);                  // csv|erp|document|manual
            $t->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->string('erp_ref')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number_key']);
            $t->index(['company_id', 'vendor_id', 'status']);
        });

        Schema::create('po_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('sku')->nullable();
            $t->string('description');
            $t->decimal('quantity', 14, 3);
            $t->string('uom', 20)->nullable();
            $t->bigInteger('unit_price_minor')->nullable();
            $t->bigInteger('amount_minor')->nullable();
            $t->timestamps();
            $t->index(['purchase_order_id', 'line_no']);
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('grn_number');
            $t->string('number_key', 60);
            $t->date('received_date')->nullable();
            $t->string('source', 10);
            $t->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->string('erp_ref')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number_key']);
        });

        Schema::create('grn_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('po_line_id')->nullable()->constrained('po_lines')->nullOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('sku')->nullable();
            $t->string('description');
            $t->decimal('quantity', 14, 3);
            $t->string('uom', 20)->nullable();
            $t->timestamps();
            $t->index('po_line_id');
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->jsonb('vendor_match')->nullable();     // {method, confidence, candidates[], extracted}
            $t->timestamp('on_hold_at')->nullable();
            $t->text('hold_reason')->nullable();
            $t->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::dropIfExists('document_matches');
        Schema::create('document_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 10);                    // two_way|three_way
            $t->string('result', 10);                  // matched|exception|accepted
            $t->boolean('is_current')->default(true);
            $t->string('po_method', 15)->nullable();   // po_number|vendor_amount
            $t->decimal('po_confidence', 5, 4)->nullable();
            $t->jsonb('header_issues');                // [{code, message, expected?, actual?}]
            $t->jsonb('discrepancies');                // header + line issues, flattened for lists
            $t->jsonb('totals');                       // {invoiced_minor, expected_minor, delta_minor, disputed_minor}
            $t->jsonb('goods_receipt_ids');
            $t->jsonb('tolerances');
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->text('accept_reason')->nullable();
            $t->foreignId('credit_note_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('credit_note_requested_at')->nullable();
            $t->bigInteger('credit_note_amount_minor')->nullable();
            $t->text('credit_note_reason')->nullable();
            $t->timestamps();
            $t->index(['invoice_id', 'is_current']);
            $t->index(['company_id', 'result', 'is_current']);
        });

        Schema::create('match_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_match_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_line_id')->nullable()->constrained('document_lines')->nullOnDelete();
            $t->foreignId('po_line_id')->nullable()->constrained('po_lines')->nullOnDelete();
            $t->unsignedSmallInteger('line_no');       // invoice line number (snapshot)
            $t->string('sku')->nullable();
            $t->string('description');
            $t->string('method', 12);                  // sku|description|none
            $t->decimal('similarity', 5, 4)->nullable();
            $t->string('result', 16);                  // matched|price_variance|qty_variance|not_received|not_on_po
            $t->jsonb('issues');                       // every code that applies, worst first
            $t->decimal('invoiced_qty', 14, 3);
            $t->decimal('ordered_qty', 14, 3)->nullable();
            $t->decimal('received_qty', 14, 3)->nullable();     // null on 2-way
            $t->decimal('previously_invoiced_qty', 14, 3)->default(0);
            $t->bigInteger('invoice_price_minor')->nullable();
            $t->bigInteger('po_price_minor')->nullable();
            $t->bigInteger('invoice_amount_minor')->nullable();
            $t->bigInteger('disputed_minor')->default(0);       // money at stake on this line
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->text('accept_reason')->nullable();
            $t->timestamps();
            $t->index(['po_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_lines');
        Schema::dropIfExists('document_matches');
        Schema::create('document_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('result', 12);
            $table->jsonb('discrepancies');
            $table->jsonb('tolerances');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'created_at']);
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('held_by');
            $t->dropColumn(['vendor_match', 'on_hold_at', 'hold_reason']);
        });
        Schema::dropIfExists('grn_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('po_lines');
        Schema::dropIfExists('purchase_orders');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS vendors_normalized_name_trgm');
        }
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'tax_key']);
            $t->dropColumn(['aliases', 'alias_keys', 'tax_key', 'source']);
        });
    }
};
