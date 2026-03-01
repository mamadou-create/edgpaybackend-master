<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des scores de crédit — permet de tracer l'évolution dans le temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_score_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->unsignedTinyInteger('score_avant');
            $table->unsignedTinyInteger('score_apres');
            $table->enum('niveau_risque_apres', ['faible', 'moyen', 'eleve']);
            $table->decimal('credit_limite_apres', 15, 2);
            $table->string('declencheur', 128)->comment('Evénement ayant déclenché le recalcul');
            $table->uuid('declencheur_id')->nullable()->comment('ID créance/transaction');
            $table->json('variables_scoring')->nullable()->comment('Snapshot des variables au moment du calcul');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_score_histories');
    }
};
