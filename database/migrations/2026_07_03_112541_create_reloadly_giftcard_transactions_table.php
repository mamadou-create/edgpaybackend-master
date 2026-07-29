<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reloadly_giftcard_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->uuid('user_id')->nullable()->index();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('reloadly_transaction_id')->nullable();
            $table->string('custom_identifier')->nullable();
            $table->unsignedInteger('product_id');
            $table->string('product_name')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 4);
            $table->decimal('total_amount', 12, 4);
            $table->string('currency_code', 5)->nullable();
            $table->string('sender_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->enum('api_status', ['PENDING', 'SUCCESS', 'FAILED', 'TIMEOUT'])->default('PENDING');
            $table->text('error_message')->nullable();
            $table->json('api_response')->nullable();
            $table->json('redeem_codes')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'api_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reloadly_giftcard_transactions');
    }
};
