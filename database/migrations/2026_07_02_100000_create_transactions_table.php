<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->nullable();
            $table->uuid('wallet_id')->nullable();

            $table->string('reference')->unique();
            $table->string('external_reference')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('correlation_id', 64)->nullable()->index();

            $table->string('type', 50)->index();
            $table->enum('direction', ['DEBIT', 'CREDIT'])->index();
            $table->enum('status', ['INITIATED', 'PENDING', 'PROCESSING', 'SUCCESS', 'FAILED', 'CANCELLED', 'EXPIRED', 'REVERSED'])->default('INITIATED')->index();

            $table->decimal('amount', 20, 2);
            $table->string('currency', 3)->default('GNF');

            $table->string('provider', 50)->nullable()->index();
            $table->string('provider_status', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
