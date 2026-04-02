<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->text('media_url')->nullable()->after('message');
            $table->string('media_type', 120)->nullable()->after('media_url');
            $table->string('media_name')->nullable()->after('media_type');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['media_url', 'media_type', 'media_name']);
        });
    }
};