<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('troc_phone_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('model');
            $table->string('storage', 50);
            $table->decimal('base_price', 10, 2);
            $table->timestamps();

            $table->unique(['model', 'storage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('troc_phone_prices');
    }
};