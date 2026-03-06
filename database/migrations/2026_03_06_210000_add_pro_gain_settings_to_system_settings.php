<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'key' => 'pro_gain_percent_on_client_cashout',
                'value' => '0',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque retrait cash client',
                'is_active' => 1,
                'is_editable' => 1,
                'order' => 30,
            ],
            [
                'key' => 'pro_gain_percent_on_client_deposit',
                'value' => '0',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                'is_active' => 1,
                'is_editable' => 1,
                'order' => 31,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('system_settings')->where('key', $row['key'])->first();

            if ($exists) {
                DB::table('system_settings')
                    ->where('key', $row['key'])
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
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', [
                'pro_gain_percent_on_client_cashout',
                'pro_gain_percent_on_client_deposit',
            ])
            ->delete();
    }
};
