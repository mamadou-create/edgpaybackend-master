<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRICE_THRESHOLD = 50000000;

    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->timestamp('publication_ends_at')->nullable()->after('publication_fee_refunded_at');
        });

        DB::table('used_item_listings')
            ->select(['id', 'created_at', 'publication_fee_base_amount', 'price', 'starting_bid'])
            ->orderBy('created_at')
            ->get()
            ->each(function (object $listing): void {
                $baseAmount = (float) ($listing->publication_fee_base_amount
                    ?? $listing->price
                    ?? $listing->starting_bid
                    ?? 0);
                $months = $baseAmount > self::PRICE_THRESHOLD ? 12 : 6;
                $startsAt = $listing->created_at
                    ? Carbon::parse($listing->created_at)
                    : now();

                DB::table('used_item_listings')
                    ->where('id', $listing->id)
                    ->update([
                        'publication_ends_at' => $startsAt
                            ->copy()
                            ->addMonthsNoOverflow($months),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropColumn('publication_ends_at');
        });
    }
};