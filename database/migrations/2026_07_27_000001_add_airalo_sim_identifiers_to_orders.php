<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('airalo_sim_id')->nullable()->after('iccid');
            $table->text('airalo_matching_id')->nullable()->after('airalo_sim_id');
        });
    }

    public function down(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->dropColumn(['airalo_sim_id', 'airalo_matching_id']);
        });
    }
};