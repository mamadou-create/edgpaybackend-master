<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_likes', function (Blueprint $table) {
            $table->uuid('announcement_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->foreign('announcement_id')
                ->references('id')
                ->on('announcements')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'announcement_id']);
        });

        Schema::create('announcement_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('announcement_id');
            $table->uuid('author_id');
            $table->text('content');
            $table->timestamps();

            $table->foreign('announcement_id')
                ->references('id')
                ->on('announcements')
                ->cascadeOnDelete();

            $table->foreign('author_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index(['announcement_id', 'created_at']);
            $table->index(['author_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_comments');
        Schema::dropIfExists('announcement_likes');
    }
};