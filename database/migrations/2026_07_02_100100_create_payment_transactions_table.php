<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('transaction_id')->nullable();
            $table->uuid('user_id')->nullable();

            $table->string('provider', 50)->index();
            $table->string('channel', 50)->nullable();
            $table->string('payment_reference')->unique();
            $table->string('merchant_reference')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('msisdn', 25)->nullable()->index();

            $table->decimal('amount', 20, 2);
            $table->string('currency', 3)->default('GNF');

            $table->enum('status', ['INITIATED', 'PENDING', 'CONFIRMED', 'FAILED', 'EXPIRED', 'CANCELLED'])->default('INITIATED')->index();
            $table->enum('confirmation_status', ['UNCONFIRMED', 'CONFIRMED', 'DISPUTED'])->default('UNCONFIRMED')->index();

            $table->string('idempotency_key')->nullable()->unique();
            $table->string('correlation_id', 64)->nullable()->index();

            $table->boolean('webhook_verified')->default(false)->index();
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('transaction_id')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
