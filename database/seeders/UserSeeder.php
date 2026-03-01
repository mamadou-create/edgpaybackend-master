<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Wallet;
use App\Models\WalletFloat;
use App\Enums\CommissionEnum;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Récupérer les rôles
        $superAdminRole = Role::where('slug', 'super_admin')->first();
        $supportAdminRole = Role::where('slug', 'support_admin')->first();
        $financeAdminRole = Role::where('slug', 'finance_admin')->first();
        $commercialAdminRole = Role::where('slug', 'commercial_admin')->first();
        $proRole = Role::where('slug', 'pro')->first();
        $userRole = Role::where('slug', 'client')->first();

        $users = [
            // 🔹 Super Admin
            [
                'display_name' => 'Super Administrator',
                'email' => 'superadmin@edgpay.com',
                'phone' => '620000001',
                'password' => Hash::make('1234'),
                'role_id' => $superAdminRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],

            // 🔹 Sous-Admin Support
            [
                'display_name' => 'Support Manager',
                'email' => 'support@edgpay.com',
                'phone' => '620000002',
                'password' => Hash::make('1234'),
                'role_id' => $supportAdminRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],

            // 🔹 Sous-Admin Finance
            [
                'display_name' => 'Finance Manager',
                'email' => 'finance@edgpay.com',
                'phone' => '620000003',
                'password' => Hash::make('1234'),
                'role_id' => $financeAdminRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],

            // 🔹 Sous-Admin Commercial
            [
                'display_name' => 'Commercial Manager',
                'email' => 'commercial@edgpay.com',
                'phone' => '620000004',
                'password' => Hash::make('1234'),
                'role_id' => $commercialAdminRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],

            // 🔸 Utilisateurs PRO
            [
                'display_name' => 'Revendeur PRO 1',
                'email' => 'pro1@example.com',
                'phone' => '621111111',
                'password' => Hash::make('1234'),
                'role_id' => $proRole->id,
                'is_pro' => true,
                'status' => true,
                'email_verified_at' => now(),
            ],
            [
                'display_name' => 'Revendeur PRO 2',
                'email' => 'pro2@example.com',
                'phone' => '621111112',
                'password' => Hash::make('1234'),
                'role_id' => $proRole->id,
                'is_pro' => true,
                'status' => true,
                'email_verified_at' => now(),
            ],

            // 🔸 Utilisateurs CLIENTS
            [
                'display_name' => 'Client Standard 1',
                'email' => 'client1@example.com',
                'phone' => '622222221',
                'password' => Hash::make('1234'),
                'role_id' => $userRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],
            [
                'display_name' => 'Client Standard 2',
                'email' => 'client2@example.com',
                'phone' => '622222222',
                'password' => Hash::make('1234'),
                'role_id' => $userRole->id,
                'is_pro' => false,
                'status' => true,
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'currency' => 'GNF',
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'commission_available' => 0,
                    'commission_balance' => 0,
                ]
            );

            foreach ([CommissionEnum::SOUS_ADMIN, CommissionEnum::EDG, CommissionEnum::GSS] as $provider) {
                WalletFloat::firstOrCreate(
                    [
                        'wallet_id' => $wallet->id,
                        'provider' => $provider,
                    ],
                    [
                        'balance' => 0,
                        'commission' => 0,
                        'rate' => 0.01,
                    ]
                );
            }
        }

        $this->command->info('Utilisateurs de démonstration créés avec succès!');
        $this->command->info('Super Admin: superadmin@edgpay.com / 1234');
        $this->command->info('Support: support@edgpay.com / 1234');
        $this->command->info('Finance: finance@edgpay.com / 1234');
        $this->command->info('Commercial: commercial@edgpay.com / 1234');
    }
}
