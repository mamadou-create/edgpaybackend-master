<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🔗 Relation avec users (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // 🔗 Référence externe Djomy
            $table->string('external_link_id')->nullable();

            // 💳 Informations principales
            $table->string('reference')->unique()->nullable();
            $table->string('payment_link_reference')->unique()->nullable();
            $table->string('transaction_id')->unique()->nullable();

            // 💰 Informations du lien
            $table->decimal('amount_to_pay', 15, 2);
            $table->string('link_name');
            $table->string('phone_number');
            $table->text('description');
            $table->string('country_code', 2);

            // 🔧 Type d'usage du lien
            $table->enum('payment_link_usage_type', [
                'UNIQUE',
                'MULTIPLE',
            ])->default('UNIQUE');

            // 📅 Dates de validité
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('date_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // 🎨 Champs personnalisés
            $table->json('custom_fields')->nullable();

            // 🔗 URL du lien
            $table->string('link_url')->nullable();

            // 🧩 Statut
            $table->enum('status', [
                'PAID',
                'ENABLED',
                'PENDING',
                'ACTIVE',
                'INACTIVE',
                'EXPIRED',
                'FAILED',
                'CANCELLED',
            ])->default('PENDING')->nullable();

            // 📦 Données brutes
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();

            // 🔍 Index
            $table->index(['user_id', 'status']);
            $table->index(['external_link_id']);
            $table->index(['reference', 'payment_link_reference']);
            $table->index(['status', 'transaction_id']);
            $table->index(['created_at']);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
        Schema::dropSoftDeletes();
    }
};
