<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creance_avoir_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('credit_profile_id');
            $table->uuid('creance_id')->nullable();
            $table->uuid('creance_transaction_id')->nullable();
            $table->uuid('created_by')->nullable();

            $table->enum('type', [
                'credit_excedent',
                'debit_utilisation',
                'annulation',
                'ajustement_admin',
                'transfert_wallet',
            ]);

            $table->decimal('montant', 15, 2);
            $table->decimal('solde_avant', 15, 2)->default(0);
            $table->decimal('solde_apres', 15, 2)->default(0);

            $table->string('reference', 128)->unique();
            $table->string('source', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('credit_profile_id')->references('id')->on('credit_profiles')->onDelete('restrict');
            $table->foreign('creance_id')->references('id')->on('creances')->onDelete('restrict');
            $table->foreign('creance_transaction_id')->references('id')->on('creance_transactions')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');

            $table->index(['user_id', 'type']);
            $table->index('creance_id');
            $table->index('creance_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creance_avoir_transactions');
    }
};