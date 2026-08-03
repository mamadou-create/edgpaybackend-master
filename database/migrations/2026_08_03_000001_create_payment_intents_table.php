<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('wallet_id')->index();
            $table->string('provider');
            $table->string('provider_reference')->unique();
            $table->decimal('amount', 12, 4);
            $table->string('currency', 8);
            $table->text('subscriber_account_number');
            $table->enum('status', ['CREATED', 'RESERVED', 'PROCESSING', 'SUCCESS', 'FAILED', 'REFUNDED', 'TIMEOUT'])->default('CREATED');
            $table->json('provider_response')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};