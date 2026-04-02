<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('city');
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
            $table->json('contact_methods')->nullable()->after('contact_email');
            $table->json('image_urls')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropColumn(['address', 'contact_email', 'contact_methods', 'image_urls']);
        });
    }
};