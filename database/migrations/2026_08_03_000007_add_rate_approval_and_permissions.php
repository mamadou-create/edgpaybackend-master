<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'create_conversion_rate' => 'Créer un taux de conversion',
        'approve_conversion_rate' => 'Approuver un taux de conversion',
        'activate_conversion_rate' => 'Activer un taux de conversion',
        'deactivate_conversion_rate' => 'Désactiver un taux de conversion',
        'view_conversion_audit' => 'Consulter l’audit des taux de conversion',
    ];

    public function up(): void
    {
        Schema::table('currency_conversion_rates', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        $now = now();
        $permissionIds = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            if ($permission === null) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => $name,
                    'slug' => $slug,
                    'module' => 'Gestion des devises',
                    'description' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $permissionIds[] = $permissionId;
            } else {
                $permissionIds[] = $permission->id;
            }
        }

        $superAdminIds = DB::table('roles')->where('is_super_admin', true)->pluck('id');
        foreach ($superAdminIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['access_level' => 'oui'],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_keys(self::PERMISSIONS))
            ->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('currency_conversion_rates', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};