<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Per-user approval limit in the invoice's currency; null = the role's limit.
            $t->decimal('approval_limit', 14, 2)->nullable()->after('role');
            $t->boolean('is_demo')->default(false)->after('approval_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['approval_limit', 'is_demo']));
    }
};
