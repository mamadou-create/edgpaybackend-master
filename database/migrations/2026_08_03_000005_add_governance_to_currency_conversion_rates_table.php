<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_conversion_rates', function (Blueprint $table) {
            $table->string('status', 16)->default('DRAFT')->after('enabled');
            $table->timestamp('effective_from')->nullable()->after('status');
            $table->timestamp('effective_to')->nullable()->after('effective_from');
            $table->uuid('created_by')->nullable()->index()->after('effective_to');
            $table->uuid('approved_by')->nullable()->index()->after('created_by');
        });

        DB::table('currency_conversion_rates')->where('enabled', true)->update(['status' => 'ACTIVE']);
        DB::table('currency_conversion_rates')->where('enabled', false)->update(['status' => 'INACTIVE']);

        Schema::create('currency_conversion_rate_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_conversion_rate_id')->constrained()->restrictOnDelete();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('action', 24);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['currency_conversion_rate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_conversion_rate_histories');

        Schema::table('currency_conversion_rates', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'effective_from',
                'effective_to',
                'created_by',
                'approved_by',
            ]);
        });
    }
};