<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assignee_id');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['company_id', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'due_date']);
            $table->dropIndex(['company_id', 'assignee_id']);
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['assigned_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
