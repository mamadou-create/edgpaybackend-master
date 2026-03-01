<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table principale des profils de crédit clients PRO.
 * Centralise limite, score, risque et statut de blocage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->decimal('credit_limite', 15, 2)->default(0);
            $table->decimal('credit_disponible', 15, 2)->default(0);
            $table->unsignedTinyInteger('score_fiabilite')->default(50)->comment('0–100');
            $table->enum('niveau_risque', ['faible', 'moyen', 'eleve'])->default('moyen');
            $table->boolean('est_bloque')->default(false);
            $table->timestamp('bloque_jusqu_au')->nullable();
            $table->text('motif_blocage')->nullable();
            $table->decimal('total_encours', 15, 2)->default(0)->comment('Somme créances non payées');
            $table->unsignedInteger('nb_creances_total')->default(0);
            $table->unsignedInteger('nb_paiements_en_retard')->default(0);
            $table->unsignedInteger('nb_paiements_rapides')->default(0);
            $table->decimal('delai_moyen_paiement_jours', 8, 2)->default(0);
            $table->unsignedInteger('anciennete_mois')->default(0);
            $table->decimal('montant_moyen_transaction', 15, 2)->default(0);
            $table->decimal('volume_mensuel_moyen', 15, 2)->default(0);
            $table->timestamp('score_calcule_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['niveau_risque', 'est_bloque']);
            $table->index('score_fiabilite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_profiles');
    }
};
