<?php

use App\Models\Announcement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('moderation_status', 30)
                ->default(Announcement::MODERATION_APPROVED)
                ->after('media_name');
            $table->text('moderation_notes')->nullable()->after('moderation_status');
            $table->timestamp('moderated_at')->nullable()->after('moderation_notes');
            $table->uuid('moderated_by')->nullable()->after('moderated_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn([
                'moderation_status',
                'moderation_notes',
                'moderated_at',
                'moderated_by',
            ]);
        });
    }
};