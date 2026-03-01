<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des transactions de remboursement sur créances.
 * Chaque versement (partiel ou total) génère un enregistrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creance_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('creance_id');
            $table->uuid('user_id')->comment('Client payeur');
            $table->uuid('validateur_id')->nullable()->comment('Admin validateur');
            $table->decimal('montant', 15, 2);
            $table->decimal('montant_avant', 15, 2)->comment('Restant avant ce paiement');
            $table->decimal('montant_apres', 15, 2)->comment('Restant après ce paiement');
            $table->enum('type', ['paiement_total', 'paiement_partiel', 'penalite', 'remise', 'annulation']);
            $table->enum('statut', ['en_attente', 'valide', 'rejete', 'annule'])->default('en_attente');
            $table->string('preuve_fichier')->nullable()->comment('Chemin du justificatif uploadé');
            $table->string('preuve_mimetype', 64)->nullable();
            $table->string('preuve_hash', 64)->nullable()->comment('SHA256 du fichier uploadé');
            $table->string('idempotency_key', 128)->unique()->nullable();
            $table->text('notes')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->ipAddress('ip_soumission')->nullable();
            $table->timestamp('valide_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('creance_id')->references('id')->on('creances')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['creance_id', 'statut']);
            $table->index(['user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creance_transactions');
    }
};
