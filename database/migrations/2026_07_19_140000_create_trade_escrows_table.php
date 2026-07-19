<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_escrows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trade_offer_id')->unique();
            $table->uuid('payer_user_id');
            $table->uuid('payee_user_id');
            $table->uuid('payer_wallet_id');
            $table->uuid('payee_wallet_id');
            $table->bigInteger('amount');
            $table->string('status', 30)->default('blocked');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('trade_offer_id')->references('id')->on('trade_offers')->cascadeOnDelete();
            $table->foreign('payer_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('payee_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('payer_wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->foreign('payee_wallet_id')->references('id')->on('wallets')->cascadeOnDelete();

            $table->index(['status', 'created_at']);
            $table->index(['payer_user_id', 'status']);
            $table->index(['payee_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_escrows');
    }
};
