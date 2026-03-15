<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('user_phone');
            $table->uuid('session_id')->nullable();
            $table->foreign('session_id')->references('id')->on('whatsapp_chat_sessions')->nullOnDelete();
            $table->string('direction');
            $table->text('message');
            $table->string('provider_message_id')->nullable();
            $table->string('intent')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('processed');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['user_phone', 'direction', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
