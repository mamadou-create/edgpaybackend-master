<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des créances PRO.
 * Chaque commande crédit génère une créance jusqu'à son remboursement total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->comment('Client PRO débiteur');
            $table->uuid('adminstrateur_id')->nullable()->comment('Admin ayant créé la créance');
            $table->string('reference', 64)->unique()->comment('Référence unique humaine');
            $table->decimal('montant_total', 15, 2);
            $table->decimal('montant_paye', 15, 2)->default(0);
            $table->decimal('montant_restant', 15, 2);
            $table->enum('statut', [
                'en_attente',      // créée, non payée
                'en_cours',        // paiement partiel reçu
                'partiellement_payee',
                'payee',           // soldée
                'en_retard',       // dépassé échéance
                'contentieux',     // recouvrement
                'annulee'
            ])->default('en_attente');
            $table->date('date_echeance')->nullable();
            $table->date('date_paiement_effectif')->nullable();
            $table->integer('jours_retard')->default(0);
            $table->text('description')->nullable();
            $table->string('idempotency_key', 128)->unique()->nullable();
            $table->json('metadata')->nullable()->comment('Données métier additionnelles');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['user_id', 'statut']);
            $table->index(['statut', 'date_echeance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creances');
    }
};
