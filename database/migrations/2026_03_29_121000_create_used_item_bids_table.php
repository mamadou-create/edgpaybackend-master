<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_item_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('bidder_id');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('used_item_listings')->cascadeOnDelete();
            $table->foreign('bidder_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['listing_id', 'amount']);
            $table->index(['bidder_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_item_bids');
    }
};