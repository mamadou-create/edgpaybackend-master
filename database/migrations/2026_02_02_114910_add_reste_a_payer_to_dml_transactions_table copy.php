<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dml_transactions', function (Blueprint $table) {
            $table->decimal('reste_a_payer', 15, 2)->nullable()->after('total_arrear');
        });

        // Copier les valeurs de total_arrear dans reste_a_payer
        DB::table('dml_transactions')->update(['reste_a_payer' => DB::raw('total_arrear')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dml_transactions', function (Blueprint $table) {
            //
        });
    }
};
