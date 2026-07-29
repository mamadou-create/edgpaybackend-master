<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reloadly_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->uuid('user_id')->nullable()->index();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('reloadly_transaction_id')->nullable();
            $table->string('custom_identifier')->nullable();
            $table->unsignedInteger('operator_id');
            $table->string('operator_name')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->string('recipient_phone');
            $table->string('recipient_email')->nullable();
            $table->decimal('requested_amount', 12, 4);
            $table->string('requested_currency', 5)->nullable();
            $table->decimal('delivered_amount', 12, 4)->nullable();
            $table->string('delivered_currency', 5)->nullable();
            $table->decimal('fee', 12, 5)->nullable();
            $table->decimal('discount', 12, 5)->nullable();
            $table->enum('api_status', ['PENDING', 'SUCCESS', 'FAILED', 'TIMEOUT'])->default('PENDING');
            $table->text('error_message')->nullable();
            $table->json('api_response')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'api_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reloadly_transactions');
    }
};
