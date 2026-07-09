<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('provider', 50)->index();
            $table->string('event_type', 100)->nullable()->index();
            $table->string('event_id', 120)->nullable();

            $table->uuid('payment_transaction_id')->nullable();

            $table->text('signature_header')->nullable();
            $table->boolean('signature_valid')->default(false)->index();

            $table->string('correlation_id', 64)->nullable()->index();
            $table->json('request_headers')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('received_at')->useCurrent()->index();
            $table->timestamp('processed_at')->nullable();
            $table->enum('status', ['RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'])->default('RECEIVED')->index();
            $table->text('processing_error')->nullable();

            $table->timestamps();

            $table->foreign('payment_transaction_id')->references('id')->on('payment_transactions')->nullOnDelete();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
