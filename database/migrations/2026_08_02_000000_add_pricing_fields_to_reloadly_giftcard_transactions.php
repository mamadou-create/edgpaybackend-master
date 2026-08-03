<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reloadly_giftcard_transactions', function (Blueprint $table) {
            $table->decimal('base_amount', 20, 4)->nullable()->after('unit_price');
            $table->decimal('commission_amount', 20, 4)->default(0)->after('base_amount');
            $table->unsignedBigInteger('wallet_amount')->nullable()->after('commission_amount');
            $table->string('wallet_currency', 5)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('reloadly_giftcard_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount',
                'commission_amount',
                'wallet_amount',
                'wallet_currency',
            ]);
        });
    }
};