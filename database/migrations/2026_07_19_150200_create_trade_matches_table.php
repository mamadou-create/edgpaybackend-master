<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('candidate_listing_id');
            $table->decimal('compatibility_score', 5, 2);
            $table->json('score_breakdown')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->foreign('listing_id')->references('id')->on('used_item_listings')->cascadeOnDelete();
            $table->foreign('candidate_listing_id')->references('id')->on('used_item_listings')->cascadeOnDelete();

            $table->unique(['listing_id', 'candidate_listing_id'], 'trade_matches_pair_unique');
            $table->index(['listing_id', 'compatibility_score']);
            $table->index(['candidate_listing_id', 'compatibility_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_matches');
    }
};
