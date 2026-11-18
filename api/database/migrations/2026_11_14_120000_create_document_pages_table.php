<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->longText('text')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('ocr_provider', 30)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};
