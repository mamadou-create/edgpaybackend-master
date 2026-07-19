<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('proposer_id');
            $table->uuid('recipient_id');
            $table->decimal('offered_estimated_value', 15, 2)->default(0);
            $table->decimal('requested_estimated_value', 15, 2)->nullable();
            $table->decimal('cash_complement', 15, 2)->default(0);
            $table->decimal('compatibility_score', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 40)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('used_item_listings')->cascadeOnDelete();
            $table->foreign('proposer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['listing_id', 'status']);
            $table->index(['proposer_id', 'status']);
            $table->index(['recipient_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offers');
    }
};
