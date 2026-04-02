<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_item_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->string('title', 160);
            $table->text('description');
            $table->string('category', 80);
            $table->string('condition_label', 80);
            $table->string('city', 120)->nullable();
            $table->string('contact_phone', 40);
            $table->decimal('price', 15, 2)->nullable();
            $table->string('sale_type', 20);
            $table->decimal('starting_bid', 15, 2)->nullable();
            $table->decimal('reserve_price', 15, 2)->nullable();
            $table->timestamp('auction_ends_at')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('seller_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['status', 'sale_type']);
            $table->index(['seller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_item_listings');
    }
};