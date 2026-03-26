<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->string('balance_target', 32)
                ->default('wallet_principal')
                ->after('amount');

            $table->index(['pro_id', 'balance_target']);
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropIndex(['pro_id', 'balance_target']);
            $table->dropColumn('balance_target');
        });
    }
};