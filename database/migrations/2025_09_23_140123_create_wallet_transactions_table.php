<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

    /**
     * Exécute la migration : Crée la table des transactions de portefeuille
     * Cette table enregistre toutes les opérations financières sur les portefeuilles
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();

            // Clé étrangère vers le portefeuille (UUID)
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');

            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Montant de la transaction (peut être positif ou négatif)
            $table->bigInteger('amount');

            // Type de transaction: commission, transfer, topup, withdrawal, etc.
            $table->string('type');

            // Référence externe pour lier à d'autres entités (ex: vente:123)
            $table->string('reference')->nullable();

            // Description textuelle de la transaction
            $table->text('description')->nullable();

            // Métadonnées supplémentaires au format JSON
            $table->json('metadata')->nullable();

            // Horodatages de création et de mise à jour
            $table->timestamps();

            // Index pour optimiser les requêtes fréquentes
            $table->index(['wallet_id', 'type', 'user_id']);

            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropSoftDeletes();
    }
};
