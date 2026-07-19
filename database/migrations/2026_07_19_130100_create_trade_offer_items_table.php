<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trade_offer_id');
            $table->uuid('listing_id')->nullable();
            $table->string('title', 160);
            $table->string('category', 80)->nullable();
            $table->string('condition_label', 80)->nullable();
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trade_offer_id')->references('id')->on('trade_offers')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('used_item_listings')->nullOnDelete();

            $table->index(['trade_offer_id', 'created_at']);
            $table->index(['listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offer_items');
    }
};
