<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('demande_pros', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();

            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Informations personnelles
            $table->string('nom');
            $table->string('prenom');
            $table->string('entreprise')->nullable();
            $table->string('ville');
            $table->string('quartier');
            // Type de pièce d'identité (CNI, Passeport, etc.)
            $table->string('type_piece');
            $table->string('numero_piece');
            $table->string('piece_image_path')->nullable();
            $table->string('email');
            $table->string('adresse');
            $table->string('telephone');
            $table->string('cancellation_reason')->nullable();

            // Statut et dates
            $table->enum('status', ['en attente', 'accepté', 'refusé', 'annulé'])->default('en attente');
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_decision')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Horodatages
            $table->timestamps();
            $table->softDeletes();

            // Contrainte d'unicité: un utilisateur ne peut avoir qu'une demande active
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demande_pros');
         Schema::dropSoftDeletes();
    }
};
