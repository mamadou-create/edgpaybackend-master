<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail complet — enregistre chaque action sensible.
 * Immuable. Ne jamais supprimer une entrée audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->comment('Acteur (admin ou client)');
            $table->uuid('cible_id')->nullable()->comment('Entité affectée (ex: client_id)');
            $table->string('cible_type', 64)->nullable()->comment('Type entité (User, Creance, etc.)');
            $table->string('action', 128)->comment('Ex: validation_paiement, blocage_compte');
            $table->string('module', 64)->default('credit')->comment('Module applicatif');
            $table->enum('resultat', ['succes', 'echec', 'tentative'])->default('succes');
            $table->json('donnees_avant')->nullable()->comment('État avant modification');
            $table->json('donnees_apres')->nullable()->comment('État après modification');
            $table->json('contexte')->nullable()->comment('Données additionnelles');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id', 128)->nullable();
            // created_at seulement — immuable
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'action']);
            $table->index(['cible_id', 'cible_type']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
