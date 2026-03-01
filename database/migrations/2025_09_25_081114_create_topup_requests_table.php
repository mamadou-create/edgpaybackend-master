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
     * Exécute la migration : Crée la table des demandes de recharge
     * Cette table gère les demandes de recharge de portefeuille par les pros
     */
    public function up(): void
    {
        Schema::create('topup_requests', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();

            // Clé étrangère vers l'utilisateur pro (UUID)
            $table->uuid('pro_id');
            $table->foreign('pro_id')->references('id')->on('users')->onDelete('cascade');

            // Clé étrangère vers l'admin décisionnaire (UUID, nullable)
            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users');

            // Montant demandé pour la recharge
            $table->bigInteger('amount')->default(0);

            // Type de recharge: CASH, EDG; GSS ou PARTNER
            $table->enum('kind', ['CASH', 'EDG', 'GSS', 'PARTNER'])->nullable();

            // Statut de la demande
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('PENDING');

            // Clé d'idempotence pour éviter les doublons
            $table->string('idempotency_key')->unique();

            // Note facultative sur la demande
            $table->text('note')->nullable();

            // Raison de la décision (approbation ou rejet)
            $table->text('cancellation_reason')->nullable();

            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_decision')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            // Horodatages
            $table->timestamps();

            // Index pour optimiser les requêtes fréquentes
            $table->index(['pro_id', 'status']);
            $table->index('idempotency_key');

            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topup_requests');
        Schema::dropSoftDeletes();
    }
};
