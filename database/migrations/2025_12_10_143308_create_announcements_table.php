<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('announcements', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->string('title');
        $table->text('message');

        // ✅ UUID foreign key
        $table->uuid('author_id');
        $table->foreign('author_id')
              ->references('id')
              ->on('users')
              ->cascadeOnDelete();

        $table->json('target_roles')->nullable();

        $table->timestamps();
        $table->softDeletes();

        $table->index('author_id');
    });

    Schema::create('announcement_reads', function (Blueprint $table) {
        $table->uuid('id')->primary();

        // ✅ UUID foreign keys
        $table->uuid('announcement_id');
        $table->uuid('user_id');

        $table->foreign('announcement_id')
              ->references('id')
              ->on('announcements')
              ->cascadeOnDelete();

        $table->foreign('user_id')
              ->references('id')
              ->on('users')
              ->cascadeOnDelete();

        $table->timestamp('read_at')->nullable();
        $table->timestamps();

        $table->unique(['announcement_id', 'user_id']);
        $table->index(['user_id', 'read_at']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
        Schema::dropSoftDeletes();
    }
};
