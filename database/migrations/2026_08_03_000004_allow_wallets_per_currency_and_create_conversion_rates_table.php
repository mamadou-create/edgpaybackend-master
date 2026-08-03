<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_id_unique');
            $table->unique(['user_id', 'currency']);
        });

        Schema::create('currency_conversion_rates', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_currency', 8);
            $table->string('payment_currency', 8);
            $table->decimal('rate', 20, 8);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['wallet_currency', 'payment_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_conversion_rates');

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_id_currency_unique');
            $table->unique('user_id');
        });
    }
};