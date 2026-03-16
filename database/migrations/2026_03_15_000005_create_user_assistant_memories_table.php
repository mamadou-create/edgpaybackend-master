<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_assistant_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 60)->index();
            $table->string('memory_key', 120)->nullable();
            $table->string('summary')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'category', 'memory_key'], 'user_memory_unique_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assistant_memories');
    }
};