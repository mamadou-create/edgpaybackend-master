<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reloadly_utility_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->uuid('user_id')->nullable()->index();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('reloadly_transaction_id')->nullable();
            $table->string('reference_id')->nullable();
            $table->unsignedInteger('biller_id');
            $table->string('biller_name')->nullable();
            $table->string('biller_type')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->string('subscriber_account_number');
            $table->decimal('amount', 12, 4);
            $table->boolean('use_local_amount')->default(false);
            $table->enum('api_status', ['PENDING', 'SUCCESS', 'FAILED', 'TIMEOUT', 'PROCESSING'])->default('PENDING');
            $table->text('error_message')->nullable();
            $table->json('api_response')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'api_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reloadly_utility_transactions');
    }
};
