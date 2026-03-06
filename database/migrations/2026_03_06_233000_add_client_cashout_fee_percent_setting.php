<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $key = 'client_cashout_fee_percent';
        $now = now();

        $row = [
            'key' => $key,
            'value' => '0',
            'type' => 'float',
            'group' => 'payments',
            'description' => 'Pourcentage de frais prélevé sur le client lors d\'un retrait cash',
            'is_active' => 1,
            'is_editable' => 1,
            'order' => 32,
        ];

        $exists = DB::table('system_settings')->where('key', $key)->first();

        if ($exists) {
            DB::table('system_settings')
                ->where('key', $key)
                ->update([
                    'value' => $row['value'],
                    'type' => $row['type'],
                    'group' => $row['group'],
                    'description' => $row['description'],
                    'is_active' => $row['is_active'],
                    'is_editable' => $row['is_editable'],
                    'order' => $row['order'],
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('system_settings')->insert([
                'id' => (string) Str::uuid(),
                'key' => $row['key'],
                'value' => $row['value'],
                'type' => $row['type'],
                'group' => $row['group'],
                'description' => $row['description'],
                'is_active' => $row['is_active'],
                'is_editable' => $row['is_editable'],
                'order' => $row['order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'client_cashout_fee_percent')
            ->delete();
    }
};
