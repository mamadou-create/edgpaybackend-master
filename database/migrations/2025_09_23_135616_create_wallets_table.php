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
     * Exécute la migration : Crée la table des portefeuilles utilisateurs
     * Cette table stocke les soldes des différents types de fonds pour chaque utilisateur
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Devise du portefeuille (GNF par défaut)
            $table->string('currency')->default('GNF');

            // Solde de trésorerie disponible
            $table->bigInteger('cash_available')->default(0);

            $table->bigInteger('blocked_amount')->default(0);

            // Commission disponible pour retrait
            $table->bigInteger('commission_available')->default(0);

            // Solde total des commissions (disponible + non disponible)
            $table->bigInteger('commission_balance')->default(0);

            // Horodatages de création et de mise à jour
            $table->timestamps();

            // Contrainte d'unicité: un utilisateur ne peut avoir qu'un portefeuille
            $table->unique('user_id');

            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
        Schema::dropSoftDeletes();
    }
};
