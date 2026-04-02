<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->decimal('publication_fee_refunded_amount', 15, 2)
                ->default(0)
                ->after('publication_fee_amount');
            $table->timestamp('publication_fee_refunded_at')
                ->nullable()
                ->after('publication_fee_refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropColumn([
                'publication_fee_refunded_amount',
                'publication_fee_refunded_at',
            ]);
        });
    }
};