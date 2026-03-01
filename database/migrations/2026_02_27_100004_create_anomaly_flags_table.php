<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des signaux d'anomalie détectés automatiquement.
 * Alimente le moteur anti-fraude.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('reference_id')->nullable()->comment('ID de la créance ou transaction concernée');
            $table->string('reference_type', 64)->nullable();
            $table->enum('type_anomalie', [
                'paiement_partiel_frequent',
                'montant_propose_faible_repetitif',
                'preuve_invalide_repetee',
                'montant_anormalement_eleve',
                'paiement_apres_delai_excessif',
                'tentative_replay',
                'depassement_limite_credit',
                'pattern_suspect',
                'autre',
            ]);
            $table->enum('niveau', ['info', 'warning', 'critique'])->default('warning');
            $table->text('description')->nullable();
            $table->json('donnees_contexte')->nullable()->comment('Données ayant déclenché l\'anomalie');
            $table->boolean('resolved')->default(false);
            $table->uuid('resolu_par')->nullable();
            $table->timestamp('resolu_at')->nullable();
            $table->text('note_resolution')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'niveau', 'resolved']);
            $table->index(['type_anomalie', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_flags');
    }
};
