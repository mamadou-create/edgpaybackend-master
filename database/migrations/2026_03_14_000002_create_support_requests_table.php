<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 40)->default('chatbot')->index();
            $table->string('reason', 120)->index();
            $table->string('status', 40)->default('open')->index();
            $table->text('last_user_message')->nullable();
            $table->json('transcript')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};