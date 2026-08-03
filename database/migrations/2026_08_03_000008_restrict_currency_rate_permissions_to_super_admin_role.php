<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION_SLUGS = [
        'create_conversion_rate',
        'approve_conversion_rate',
        'activate_conversion_rate',
        'deactivate_conversion_rate',
        'view_conversion_audit',
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSION_SLUGS)->pluck('id');
        $technicalRoleIds = DB::table('roles')
            ->where('is_super_admin', true)
            ->whereNotIn('slug', ['super_admin', 'super-admin'])
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $technicalRoleIds)
            ->delete();
    }

    public function down(): void {}
};