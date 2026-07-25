<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sensitiveColumns = ['iccid', 'qrcode_url', 'smdp_address', 'ac_code'];
        $existingColumns = array_values(array_filter(
            $sensitiveColumns,
            static fn (string $column): bool => Schema::hasColumn('airalo_orders', $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('airalo_orders', function (Blueprint $table) use ($existingColumns): void {
            if (Schema::hasColumn('airalo_orders', 'iccid')) {
                $table->dropIndex(['iccid']);
            }

            $table->dropColumn($existingColumns);
        });
    }

    public function down(): void
    {
        // Intentional no-op: rollback must never recreate sensitive eSIM fields.
    }
};