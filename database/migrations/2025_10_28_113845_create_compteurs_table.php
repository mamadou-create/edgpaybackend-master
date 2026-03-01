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
        Schema::create('compteurs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🔗 Relation avec users (UUID)
            $table->uuid('client_id');
            $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('display_name');
            $table->string('compteur')->unique();
            // Type de compteur
            $table->enum('type_compteur', ['prepaid', 'postpayment']);

            $table->timestamps();
            $table->softDeletes();

             $table->index(['client_id', 'type_compteur']);
             $table->index(['compteur']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compteurs');
        Schema::dropSoftDeletes();
    }
};
