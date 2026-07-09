<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->uuid('payment_transaction_id')->nullable();

            $table->string('operator_id', 50)->index();
            $table->string('operator_name')->nullable();
            $table->string('recipient_msisdn', 25)->index();
            $table->string('country_code', 2);

            $table->string('data_plan_id', 60)->index();
            $table->string('data_plan_name')->nullable();
            $table->string('validity')->nullable();
            $table->string('allowance')->nullable();

            $table->decimal('amount', 20, 2);
            $table->decimal('local_amount', 20, 2)->nullable();
            $table->string('local_currency', 3)->default('GNF');

            $table->string('reloadly_transaction_id')->nullable()->unique();
            $table->enum('status', ['PENDING', 'PROCESSING', 'SUCCESS', 'FAILED', 'CANCELLED'])->default('PENDING')->index();

            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('commission_amount', 20, 2)->nullable();
            $table->timestamp('delivered_at')->nullable()->index();

            $table->string('correlation_id', 64)->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('payment_transaction_id')->references('id')->on('payment_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_orders');
    }
};
