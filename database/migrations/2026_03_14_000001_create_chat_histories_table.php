<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id', 64)->index();
            $table->string('role', 20);
            $table->text('content');
            $table->string('intent', 60)->nullable()->index();
            $table->json('entities')->nullable();
            $table->json('context')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('escalated_to_support')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_histories');
    }
};