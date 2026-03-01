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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('display_name');
            $table->boolean('is_pro')->default(false);
            $table->boolean('status')->default(false);
            $table->bigInteger('solde_portefeuille')->default(0);
            $table->bigInteger('commission_portefeuille')->default(0);
            $table->string('password');
            $table->string('profile_photo_path')->nullable();
            $table->string('otp')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_token')->nullable();
            $table->timestamp('two_factor_expires_at')->nullable();
            $table->text('activation_token')->nullable();
            $table->timestamp('activation_account_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_reset_token')->nullable();
            $table->timestamp('password_reset_expires_at')->nullable();

            $table->uuid('role_id');
            $table->foreign('role_id')->references('id')->on('roles');

              // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('assigned_user')->nullable();
            $table->foreign('assigned_user')->references('id')->on('users')->onDelete('cascade');

            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropSoftDeletes();
    }
};
