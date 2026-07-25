<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'airalo_orders_user_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->dropIndex('airalo_orders_user_created_at_index');
        });
    }
};