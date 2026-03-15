<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('display_name');
            }

            if (!Schema::hasColumn('users', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('password');
            }

            if (!Schema::hasColumn('users', 'whatsapp_phone')) {
                $table->string('whatsapp_phone')->nullable()->unique()->after('phone');
            }

            if (!Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_phone');
            }

            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('whatsapp_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['date_of_birth', 'pin_hash', 'whatsapp_phone', 'whatsapp_verified_at', 'phone_verified_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
