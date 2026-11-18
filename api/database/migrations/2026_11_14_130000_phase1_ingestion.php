<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            // Type the uploader declared (wins over the AI's guess); null = detect automatically.
            $table->string('declared_type', 20)->nullable()->after('type');
            $table->index(['company_id', 'source']);
        });

        Schema::table('document_pages', function (Blueprint $table) {
            $table->string('image_disk', 20)->nullable()->after('page_number');
            $table->string('image_path')->nullable()->after('image_disk');
            $table->unsignedInteger('width')->nullable()->after('image_path');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedSmallInteger('dpi')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('document_pages', fn (Blueprint $t) => $t->dropColumn(['image_disk', 'image_path', 'width', 'height', 'dpi']));
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropIndex(['company_id', 'source']);
            $table->dropColumn('declared_type');
        });
    }
};
