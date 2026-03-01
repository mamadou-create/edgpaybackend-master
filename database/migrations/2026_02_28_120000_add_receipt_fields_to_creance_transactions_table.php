<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creance_transactions', function (Blueprint $table) {
            $table->string('receipt_number', 64)->nullable()->unique()->after('preuve_hash');
            $table->timestamp('receipt_issued_at')->nullable()->after('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('creance_transactions', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['receipt_number', 'receipt_issued_at']);
        });
    }
};
