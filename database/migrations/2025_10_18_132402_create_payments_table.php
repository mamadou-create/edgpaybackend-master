<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🔗 Relation avec users (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // 💳 Informations principales
            $table->string('merchant_payment_reference')->unique();
            $table->string('transaction_id')->unique()->nullable();
            $table->string('compteur_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('payer_identifier');

            // 🔧 Méthode de paiement enum
            $table->enum('payment_method', [
                'MOMO',     // Mobile Money (MTN, Orange, etc.)
                'OM',       // Orange Money
                'VISA',     // Carte bancaire
                'KULU',     // KULU (spécifique)
                'SOUTOURA', //Soutoura Money
                'MC',        //MasterCard
                'PAYCARD',   // PayCard 
                'YMO',       // YMO
                'AMEX'      //American Express 
            ])->default('OM');

            // 🔧 Type de paiement (direct ou gateway)
            $table->enum('payment_type', [
                'DIRECT',   // Paiement direct
                'GATEWAY',  // Paiement via passerelle
            ])->default('DIRECT');

            $table->decimal('amount', 15, 2);
            $table->string('country_code', 2);
            $table->string('currency_code', 3);
            $table->string('description')->nullable();

            // 🧩 Statut en Enum
            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'SUCCESS',
                'FAILED',
                'CANCELLED',
                'EXPIRED',
            ])->default('PENDING');

            // 🔗 Références externes et URLs
            $table->string('external_reference')->nullable();
            $table->string('gateway_url')->nullable();

            // 📦 Données brutes pour débogage
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('service_type')->nullable();
            $table->string('dml_reference')->nullable();
            $table->integer('processing_attempts')->default(0);

            // 🔍 Index optimisés
            $table->index(['user_id', 'status']);
            $table->index(['merchant_payment_reference', 'transaction_id']);
            $table->index(['external_reference']);
            $table->index(['status']);
            $table->index(['created_at']);


            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropSoftDeletes();
    }
};
