<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const COMMISSION_KEY = 'occasion_publication_fee_rate';

    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->decimal('publication_fee_rate', 8, 6)->default(0)->after('image_urls');
            $table->decimal('publication_fee_base_amount', 15, 2)->default(0)->after('publication_fee_rate');
            $table->decimal('publication_fee_amount', 15, 2)->default(0)->after('publication_fee_base_amount');
        });

        $existing = DB::table('commissions')
            ->where('key', self::COMMISSION_KEY)
            ->first();

        if (!$existing) {
            DB::table('commissions')->insert([
                'id' => (string) Str::uuid(),
                'key' => self::COMMISSION_KEY,
                'value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropColumn([
                'publication_fee_rate',
                'publication_fee_base_amount',
                'publication_fee_amount',
            ]);
        });

        DB::table('commissions')
            ->where('key', self::COMMISSION_KEY)
            ->delete();
    }
};