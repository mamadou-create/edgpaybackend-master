<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->string('package_title')->nullable()->after('package_id');
            $table->string('destination')->nullable()->after('package_title');
            $table->string('data_volume')->nullable()->after('destination');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('data_volume');
            $table->string('operator_name')->nullable()->after('validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('airalo_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'package_title',
                'destination',
                'data_volume',
                'validity_days',
                'operator_name',
            ]);
        });
    }
};