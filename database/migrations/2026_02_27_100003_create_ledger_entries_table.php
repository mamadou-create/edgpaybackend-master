<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger comptable IMMUABLE.
 * Chaque ligne est une écriture définitive — jamais modifiée, jamais supprimée.
 * Hash d'intégrité = SHA256(client_id|montant|timestamp|secret_key)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('type', ['debit', 'credit', 'penalite', 'remise', 'ajustement_admin']);
            $table->decimal('montant', 15, 2);
            $table->decimal('balance_avant', 15, 2)->comment('Encours avant écriture');
            $table->decimal('balance_apres', 15, 2)->comment('Encours après écriture');
            $table->string('reference_type', 64)->comment('Morphable: creance, creance_transaction, etc.');
            $table->uuid('reference_id');
            $table->string('description')->nullable();
            $table->string('hash_integrite', 128)->comment('SHA256 pour vérification d\'immuabilité');
            $table->string('precedent_hash', 128)->nullable()->comment('Hash entrée précédente (chaîne)');
            $table->uuid('cree_par')->nullable()->comment('User admin si action manuelle');
            // Pas de updated_at — entrée immuable
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['user_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
