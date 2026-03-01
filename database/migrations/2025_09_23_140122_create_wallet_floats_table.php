<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_floats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Clé étrangère vers le portefeuille (UUID)
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->string('provider');
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('commission')->default(0);
            // Taux de commission (1% par défaut)
            $table->decimal('rate', 5, 3)->default(0.01);
           
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['wallet_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_floats');
        Schema::dropSoftDeletes();
    }
};
