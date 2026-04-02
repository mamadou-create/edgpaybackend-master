<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->unsignedInteger('diffusion_duration_days')->nullable()->after('publication_fee_amount');
            $table->timestamp('diffusion_starts_at')->nullable()->after('diffusion_duration_days');
            $table->timestamp('diffusion_ends_at')->nullable()->after('diffusion_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn([
                'diffusion_duration_days',
                'diffusion_starts_at',
                'diffusion_ends_at',
            ]);
        });
    }
};
