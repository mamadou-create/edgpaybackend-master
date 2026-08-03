<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('payment_currency', 8)->nullable()->after('currency');
            $table->string('wallet_currency', 8)->nullable()->after('payment_currency');
            $table->decimal('conversion_rate', 20, 8)->nullable()->after('wallet_currency');
            $table->decimal('converted_amount', 16, 4)->nullable()->after('conversion_rate');
            $table->boolean('conversion_applied')->default(false)->after('converted_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn([
                'payment_currency',
                'wallet_currency',
                'conversion_rate',
                'converted_amount',
                'conversion_applied',
            ]);
        });
    }
};