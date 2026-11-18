<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('tax_id')->nullable();
            $table->string('email')->nullable();
            $table->char('default_currency', 3)->default('USD');
            $table->string('erp_ref')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_name']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('unknown')->index();      // invoice|purchase_order|goods_receipt|unknown
            $table->string('source', 10)->default('upload');              // upload|email
            $table->string('status', 20)->default('received')->index();
            // file
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_disk', 20);
            $table->string('storage_path');
            $table->char('sha256', 64);
            // ingestion meta
            $table->string('email_from')->nullable();
            $table->string('email_subject')->nullable();
            $table->string('email_message_id')->nullable();
            // pipeline output
            $table->longText('ocr_text')->nullable();
            $table->string('ocr_provider', 30)->nullable();
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('llm_provider', 30)->nullable();
            $table->string('llm_model', 60)->nullable();
            $table->jsonb('extraction')->nullable();          // raw normalized LLM payload incl. per-field confidence
            $table->decimal('confidence', 5, 4)->nullable();  // overall
            // header fields (editable by reviewer)
            $table->string('vendor_name')->nullable();
            $table->string('document_number')->nullable()->index();
            $table->string('po_number')->nullable()->index();
            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('subtotal_minor')->nullable();
            $table->bigInteger('tax_minor')->nullable();
            $table->bigInteger('total_minor')->nullable();
            $table->jsonb('validation')->nullable();          // {errors:[], warnings:[]}
            $table->text('last_error')->nullable();
            // workflow
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('erp_connector', 30)->nullable();
            $table->string('erp_ref')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'sha256']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('sku')->nullable();
            $table->string('description');
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'line_no']);
        });

        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step', 30);          // ingest|ocr|extract|validate|review|approve|match|push|...
            $table->string('status', 15);        // succeeded|failed|skipped|info
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->text('error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['document_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('document_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('kind', 10);          // two_way|three_way
            $table->string('result', 12);        // matched|exception|overridden
            $table->jsonb('discrepancies');
            $table->jsonb('tolerances');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('erp_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('connector', 30);
            $table->string('idempotency_key')->unique();
            $table->string('status', 12);        // succeeded|failed
            $table->string('external_ref')->nullable();
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['erp_pushes', 'document_matches', 'document_events', 'document_lines', 'documents', 'vendors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
