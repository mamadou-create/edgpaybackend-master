<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offer_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trade_offer_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->uuid('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->foreign('trade_offer_id')->references('id')->on('trade_offers')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['trade_offer_id', 'changed_at']);
            $table->index(['to_status', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offer_status_histories');
    }
};
