<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_profiles', function (Blueprint $table) {
            $table->decimal('avoir_creance_disponible', 15, 2)
                ->default(0)
                ->after('total_encours');

            $table->decimal('avoir_creance_cumule', 15, 2)
                ->default(0)
                ->after('avoir_creance_disponible');
        });
    }

    public function down(): void
    {
        Schema::table('credit_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'avoir_creance_disponible',
                'avoir_creance_cumule',
            ]);
        });
    }
};