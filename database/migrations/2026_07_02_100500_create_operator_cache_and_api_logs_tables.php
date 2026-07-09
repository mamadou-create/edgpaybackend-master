<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('provider', 50)->default('RELOADLY')->index();
            $table->string('operator_code', 80);
            $table->string('operator_name');
            $table->string('country_code', 2)->index();
            $table->string('network')->nullable();

            $table->boolean('supports_airtime')->default(true);
            $table->boolean('supports_data')->default(false);

            $table->decimal('min_amount', 20, 2)->nullable();
            $table->decimal('max_amount', 20, 2)->nullable();

            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['provider', 'operator_code', 'country_code']);
        });

        Schema::create('api_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->nullable();

            $table->string('service', 100)->index();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->integer('status_code')->nullable()->index();
            $table->integer('duration_ms')->nullable();

            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('idempotency_key')->nullable()->index();

            $table->string('request_ip', 45)->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
        Schema::dropIfExists('operator_cache');
    }
};
