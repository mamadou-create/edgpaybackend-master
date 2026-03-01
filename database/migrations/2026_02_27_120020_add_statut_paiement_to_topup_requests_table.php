<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('topup_requests', 'statut_paiement')) {
                $table->enum('statut_paiement', ['paye', 'impaye'])->nullable()->after('status');
                $table->index('statut_paiement', 'topup_requests_statut_paiement_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            if (Schema::hasColumn('topup_requests', 'statut_paiement')) {
                $table->dropIndex('topup_requests_statut_paiement_index');
                $table->dropColumn('statut_paiement');
            }
        });
    }
};
