<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('credit_profiles', 'avoir_creance_disponible')) {
                $table->dropColumn('avoir_creance_disponible');
            }

            if (Schema::hasColumn('credit_profiles', 'avoir_creance_cumule')) {
                $table->dropColumn('avoir_creance_cumule');
            }
        });

        Schema::dropIfExists('creance_avoir_transactions');
    }

    public function down(): void
    {
        Schema::table('credit_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_profiles', 'avoir_creance_disponible')) {
                $table->decimal('avoir_creance_disponible', 15, 2)
                    ->default(0)
                    ->after('total_encours');
            }

            if (!Schema::hasColumn('credit_profiles', 'avoir_creance_cumule')) {
                $table->decimal('avoir_creance_cumule', 15, 2)
                    ->default(0)
                    ->after('avoir_creance_disponible');
            }
        });

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
        });
    }
};