<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->string('airalo_order_code')->nullable()->unique()->after('airalo_order_id');
            $table->text('iccid')->nullable()->after('airalo_order_code');
        });
    }

    public function down(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->dropUnique(['airalo_order_code']);
            $table->dropColumn(['airalo_order_code', 'iccid']);
        });
    }
};