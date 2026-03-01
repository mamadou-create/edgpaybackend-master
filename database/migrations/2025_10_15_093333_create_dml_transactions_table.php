<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('dml_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->uuid('payment_id');


            // Type de transaction
            $table->enum('transaction_type', ['prepaid', 'postpayment']);

            // Données de la requête
            $table->string('rst_value')->nullable();
            $table->string('rst_code')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('code')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('buy_last_date')->nullable();
            $table->string('device')->nullable();
            $table->decimal('montant', 15, 2)->nullable();
            $table->decimal('total_arrear', 15, 2)->nullable();
            $table->string('ref_facture')->nullable();

            // Données spécifiques aux transactions prépayées
            $table->string('trans_id')->nullable();
            $table->timestamp('trans_time')->nullable();
            $table->string('ref_code')->nullable();
            $table->decimal('kwh', 10, 2)->nullable();
            $table->decimal('kwh_amt', 15, 2)->nullable();
            $table->decimal('fee_amt', 15, 2)->nullable();
            $table->decimal('arrear_amt', 15, 2)->nullable();
            $table->decimal('vat', 15, 2)->nullable();
            $table->decimal('net_amt', 15, 2)->nullable();
            $table->text('tokens')->nullable();
            $table->string('verify_code')->nullable();

            // Données spécifiques aux recherches
            $table->integer('state')->nullable();
            $table->string('seed')->nullable();
            $table->date('reg_date')->nullable();
            $table->string('buy_times')->nullable();
            $table->decimal('buy_monthly', 15, 4)->nullable();
            $table->decimal('supply_amt', 15, 2)->nullable();
            $table->string('sign')->nullable();

            // Données pour les factures postpayées
            $table->json('customer_bills')->nullable();

            // Données de réponse
            $table->json('api_response')->nullable();
            $table->string('api_status')->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Index
            $table->index(['user_id', 'transaction_type']);
            $table->index(['payment_id']);
            $table->index('trans_id');
            $table->index('ref_code');
            $table->index('created_at');
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dml_transactions');
        Schema::dropSoftDeletes();
    }
};
