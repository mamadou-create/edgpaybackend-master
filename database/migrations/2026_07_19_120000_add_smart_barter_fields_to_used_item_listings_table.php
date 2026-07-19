<?php

use App\Models\UsedItemListing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->string('transaction_type', 30)
                ->default(UsedItemListing::TRANSACTION_TYPE_SALE)
                ->after('contact_methods');
            $table->boolean('accepts_barter')->default(false)->after('transaction_type');
            $table->string('wanted_object', 255)->nullable()->after('accepts_barter');
            $table->json('wanted_objects')->nullable()->after('wanted_object');
            $table->string('wanted_category', 80)->nullable()->after('wanted_objects');
            $table->decimal('wanted_value', 15, 2)->nullable()->after('wanted_category');
            $table->decimal('estimated_object_value', 15, 2)->nullable()->after('wanted_value');
            $table->boolean('accepts_topup')->default(false)->after('estimated_object_value');
            $table->decimal('topup_min_amount', 15, 2)->nullable()->after('accepts_topup');
            $table->decimal('topup_max_amount', 15, 2)->nullable()->after('topup_min_amount');
            $table->unsignedInteger('max_distance_km')->nullable()->after('topup_max_amount');
            $table->boolean('negotiable')->default(false)->after('max_distance_km');
            $table->string('warranty', 255)->nullable()->after('negotiable');
            $table->string('item_condition', 80)->nullable()->after('warranty');
            $table->string('listing_status', 30)->default(UsedItemListing::STATUS_ACTIVE)->after('status');
            $table->decimal('listing_quality_score', 5, 2)->default(0)->after('listing_status');

            $table->index(['transaction_type', 'status']);
            $table->index(['accepts_barter', 'category']);
            $table->index(['wanted_category', 'city']);
            $table->index(['item_condition', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('used_item_listings', function (Blueprint $table) {
            $table->dropIndex(['transaction_type', 'status']);
            $table->dropIndex(['accepts_barter', 'category']);
            $table->dropIndex(['wanted_category', 'city']);
            $table->dropIndex(['item_condition', 'created_at']);

            $table->dropColumn([
                'transaction_type',
                'accepts_barter',
                'wanted_object',
                'wanted_objects',
                'wanted_category',
                'wanted_value',
                'estimated_object_value',
                'accepts_topup',
                'topup_min_amount',
                'topup_max_amount',
                'max_distance_km',
                'negotiable',
                'warranty',
                'item_condition',
                'listing_status',
                'listing_quality_score',
            ]);
        });
    }
};
