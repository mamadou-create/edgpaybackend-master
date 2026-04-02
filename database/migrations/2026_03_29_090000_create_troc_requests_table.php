<?php

use App\Models\TrocRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('troc_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('source_model');
            $table->string('source_storage', 50);
            $table->unsignedTinyInteger('battery');
            $table->string('condition', 50);
            $table->json('condition_details')->nullable();
            $table->string('image_url')->nullable();
            $table->json('image_analysis')->nullable();
            $table->decimal('estimated_price', 14, 2);
            $table->string('target_model');
            $table->string('target_storage', 50);
            $table->decimal('target_price', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->string('currency', 10)->default('GNF');
            $table->text('offer_message')->nullable();
            $table->string('status', 30)->default(TrocRequest::STATUS_PENDING)->index();
            $table->text('admin_notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('troc_requests');
    }
};