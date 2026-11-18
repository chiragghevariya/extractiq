<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('schema_version', 40)->nullable()->after('llm_model');   // e.g. invoice.v1
            $table->jsonb('rule_results')->nullable()->after('validation');         // every rule: pass|warn|fail + message
            $table->unsignedInteger('llm_input_tokens')->nullable()->after('schema_version');
            $table->unsignedInteger('llm_output_tokens')->nullable()->after('llm_input_tokens');
            $table->decimal('llm_cost_usd', 12, 6)->nullable()->after('llm_output_tokens');
            $table->bigInteger('shipping_minor')->nullable()->after('tax_minor');
            $table->string('payment_terms')->nullable()->after('due_date');
        });

        Schema::table('document_lines', function (Blueprint $table) {
            $table->string('uom', 20)->nullable()->after('quantity');
            $table->decimal('tax_rate', 6, 3)->nullable()->after('unit_price_minor');
        });

        // Vendor memory: hints learned from human corrections, injected into the next extraction.
        Schema::create('vendor_hints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);                 // schema field path, e.g. po_number, vendor.tax_id
            $table->string('label')->nullable();         // label printed next to the value, e.g. "Order Ref"
            $table->string('hint', 500);                 // sentence given to the model
            $table->string('example_value')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['vendor_id', 'field', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_hints');
        Schema::table('document_lines', fn (Blueprint $t) => $t->dropColumn(['uom', 'tax_rate']));
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['schema_version', 'rule_results', 'llm_input_tokens', 'llm_output_tokens', 'llm_cost_usd', 'shipping_minor', 'payment_terms']));
    }
};
