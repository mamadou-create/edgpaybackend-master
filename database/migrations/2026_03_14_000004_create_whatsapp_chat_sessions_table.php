<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_phone')->unique();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('state')->default('idle');
            $table->json('context')->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->index(['user_phone', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_sessions');
    }
};
