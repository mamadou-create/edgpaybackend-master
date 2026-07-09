<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('troc_car_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand', 80);
            $table->string('model', 120);
            $table->unsignedSmallInteger('year');
            $table->string('fuel', 30)->nullable();
            $table->string('transmission', 30)->nullable();
            $table->decimal('base_price', 14, 2);
            $table->timestamps();

            $table->unique(['brand', 'model', 'year', 'fuel', 'transmission'], 'troc_car_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('troc_car_prices');
    }
};
