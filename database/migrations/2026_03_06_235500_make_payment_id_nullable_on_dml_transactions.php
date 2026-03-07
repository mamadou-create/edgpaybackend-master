<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dml_transactions') || !Schema::hasColumn('dml_transactions', 'payment_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE dml_transactions MODIFY payment_id CHAR(36) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('dml_transactions') || !Schema::hasColumn('dml_transactions', 'payment_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE dml_transactions MODIFY payment_id CHAR(36) NOT NULL');
    }
};
