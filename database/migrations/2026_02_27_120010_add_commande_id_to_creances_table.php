<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creances', function (Blueprint $table) {
            if (!Schema::hasColumn('creances', 'commande_id')) {
                $table->uuid('commande_id')->nullable()->after('adminstrateur_id');
                $table->unique('commande_id', 'creances_commande_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('creances', function (Blueprint $table) {
            if (Schema::hasColumn('creances', 'commande_id')) {
                $table->dropUnique('creances_commande_id_unique');
                $table->dropColumn('commande_id');
            }
        });
    }
};
