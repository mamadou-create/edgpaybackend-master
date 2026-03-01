<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ajouter la colonne nullable
        Schema::table('dml_transactions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('id');
        });

        // 2. Générer des clés pour les enregistrements existants
        DB::table('dml_transactions')->whereNull('idempotency_key')->orderBy('id')->chunk(100, function ($transactions) {
            foreach ($transactions as $transaction) {
                $key = hash('sha256', $transaction->id . '|' . ($transaction->created_at ?? now()) . '|' . random_int(1, 999999));
                // Ou plus simplement : sha1($transaction->id . $transaction->created_at)
                DB::table('dml_transactions')
                    ->where('id', $transaction->id)
                    ->update(['idempotency_key' => $key]);
            }
        });

        // 3. Ajouter l'index et la contrainte unique
        Schema::table('dml_transactions', function (Blueprint $table) {
            $table->index('idempotency_key');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('dml_transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};