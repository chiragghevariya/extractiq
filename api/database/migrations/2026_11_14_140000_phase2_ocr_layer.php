<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            // Normalised OCR for the page: {words[], lines[], key_values[], tables[]}; bboxes are 0..1 ratios.
            $table->jsonb('ocr')->nullable()->after('text');
            // Provider response for this page, kept verbatim for audit/debugging.
            $table->jsonb('ocr_raw')->nullable()->after('ocr');
        });

        Schema::table('documents', function (Blueprint $table) {
            // Where the type came from: declared (upload) | classifier (OCR keywords) | llm | manual (reviewer).
            $table->string('type_source', 12)->nullable()->after('declared_type');
            // Textract async job id while a multi-page analysis is running.
            $table->string('ocr_job_id')->nullable()->after('ocr_provider');
        });
    }

    public function down(): void
    {
        Schema::table('document_pages', fn (Blueprint $t) => $t->dropColumn(['ocr', 'ocr_raw']));
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['type_source', 'ocr_job_id']));
    }
};
