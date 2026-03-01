<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creance_transactions', function (Blueprint $table) {
            // Clé de groupement pour une soumission "payer-total" (plusieurs transactions).
            // À ne pas confondre avec idempotency_key qui reste UNIQUE (anti-replay / anti double-clic).
            $table->string('batch_key', 128)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('creance_transactions', function (Blueprint $table) {
            $table->dropIndex(['batch_key']);
            $table->dropColumn('batch_key');
        });
    }
};
