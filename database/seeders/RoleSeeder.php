<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
             [
                'name' => 'Api Client',
                'slug' => 'api_client',
                'description' => 'Client API',
                'is_super_admin' => true,
                'permissions' => 'all' // Toutes les permissions avec niveau "oui"
            ],
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'description' => 'Propriétaire absolu de la plateforme avec tous les droits',
                'is_super_admin' => true,
                'permissions' => 'all' // Toutes les permissions avec niveau "oui"
            ],
            [
                'name' => 'Sous-Admin Support',
                'slug' => 'support_admin',
                'description' => 'Gestion du support client et résolution des problèmes',
                'is_super_admin' => false,
                'permissions' => [
                    'users.view_profiles' => 'oui',
                    'users.activate_deactivate' => 'limité',
                    'transactions.view_all' => 'oui',
                    'transactions.cancel' => 'limité',
                    'transactions.reissue_receipt' => 'oui',
                    'transactions.validate_pending' => 'oui',
                    'pros.view_transactions' => 'oui',
                    'tech.view_error_logs' => 'limité',
                    'reports.operational' => 'oui',
                    'reports.realtime_dashboard' => 'oui',
                ]
            ],
            [
                'name' => 'Sous-Admin Finance',
                'slug' => 'finance_admin',
                'description' => 'Gestion financière, trésorerie et retraits',
                'is_super_admin' => false,
                'permissions' => [
                    'users.view_profiles' => 'oui',
                    'transactions.view_all' => 'oui',
                    'transactions.cancel' => 'limité',
                    'transactions.reissue_receipt' => 'oui',
                    'transactions.validate_pending' => 'oui',
                    'finances.view_balances' => 'oui',
                    'finances.manage_withdrawals' => 'oui',
                    'finances.export_accounting' => 'oui',
                    'pros.view_transactions' => 'oui',
                    'tech.view_error_logs' => 'limité',
                    'reports.financial' => 'oui',
                    'reports.operational' => 'limité',
                    'reports.realtime_dashboard' => 'oui',
                    'credits.manage' => 'oui',
                ]
            ],
            [
                'name' => 'Sous-Admin Commercial',
                'slug' => 'commercial_admin',
                'description' => 'Gestion des revendeurs PRO et développement commercial',
                'is_super_admin' => false,
                'permissions' => [
                    'users.view_profiles' => 'oui',
                    'users.activate_deactivate' => 'limité',
                    'transactions.view_all' => 'oui', // Pro uniquement en logique métier
                    'transactions.reissue_receipt' => 'oui',
                    'transactions.validate_pending' => 'limité',
                    'finances.manual_credit_debit' => 'limité',
                    'pros.validate_new' => 'oui',
                    'pros.view_transactions' => 'oui',
                    'pros.adjust_limits' => 'limité',
                    'tech.view_error_logs' => 'limité',
                    'reports.operational' => 'limité',
                    'reports.realtime_dashboard' => 'oui',
                    'credits.manage' => 'limité',
                ]
            ],
            [
                'name' => 'PRO',
                'slug' => 'pro',
                'description' => 'Revendeur professionnel avec accès aux fonctionnalités business',
                'is_super_admin' => false,
                'permissions' => [
                    'users.view_profiles' => 'non', // Ne voit que son profil
                    'transactions.view_all' => 'non', // Ne voit que ses transactions
                    'transactions.reissue_receipt' => 'oui', // Pour ses transactions
                    'reports.realtime_dashboard' => 'limité', // Version limitée
                ]
            ],
            [
                'name' => 'Client',
                'slug' => 'client',
                'description' => 'Client standard de la plateforme',
                'is_super_admin' => false,
                'permissions' => [
                    'users.view_profiles' => 'non', // Ne voit que son profil
                    'transactions.view_all' => 'non', // Ne voit que ses transactions
                    'transactions.reissue_receipt' => 'oui', // Pour ses transactions
                ]
            ],
        ];

        foreach ($roles as $roleData) {
            $role = \App\Models\Role::firstOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                    'is_super_admin' => $roleData['is_super_admin']
                ]
            );

            // Attacher les permissions
            if ($roleData['permissions'] === 'all') {
                $permissions = \App\Models\Permission::all();
                $pivotData = $permissions->pluck('id')->mapWithKeys(fn($id) => [$id => ['access_level' => 'oui']])->toArray();
                $role->permissions()->syncWithoutDetaching($pivotData);
            } else {
                $permissionIds = [];
                foreach ($roleData['permissions'] as $permissionSlug => $accessLevel) {
                    $permission = \App\Models\Permission::where('slug', $permissionSlug)->first();
                    if ($permission) {
                        $permissionIds[$permission->id] = ['access_level' => $accessLevel];
                    }
                }
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }
        }
    }
}
