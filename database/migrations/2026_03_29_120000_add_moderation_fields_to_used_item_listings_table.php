<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->string('moderation_status', 20)->default('pending')->after('status');
            $table->text('admin_notes')->nullable()->after('moderation_status');
            $table->index(['moderation_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropIndex(['moderation_status', 'status']);
            $table->dropColumn(['moderation_status', 'admin_notes']);
        });
    }
};