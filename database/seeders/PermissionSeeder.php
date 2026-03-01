<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            // Gestion des comptes
            [
                'module' => 'Gestion des comptes',
                'name' => 'Créer/supprimer utilisateurs',
                'slug' => 'users.create_delete',
                'description' => 'Permet de créer et supprimer des comptes utilisateurs'
            ],
            [
                'module' => 'Gestion des comptes',
                'name' => 'Modifier rôle',
                'slug' => 'users.modify_roles',
                'description' => 'Permet de promouvoir ou rétrograder les rôles utilisateurs'
            ],
            [
                'module' => 'Gestion des comptes',
                'name' => 'Activer/désactiver compte',
                'slug' => 'users.activate_deactivate',
                'description' => 'Permet d\'activer ou désactiver un compte utilisateur'
            ],
            [
                'module' => 'Gestion des comptes',
                'name' => 'Voir profils utilisateurs',
                'slug' => 'users.view_profiles',
                'description' => 'Permet de consulter les profils des utilisateurs'
            ],

            // Transactions
            [
                'module' => 'Transactions',
                'name' => 'Voir toutes les transactions',
                'slug' => 'transactions.view_all',
                'description' => 'Accès à l\'historique complet des transactions'
            ],
            [
                'module' => 'Transactions',
                'name' => 'Annuler une transaction',
                'slug' => 'transactions.cancel',
                'description' => 'Permet d\'annuler une transaction existante'
            ],
            [
                'module' => 'Transactions',
                'name' => 'Rééditer un reçu',
                'slug' => 'transactions.reissue_receipt',
                'description' => 'Permet de régénérer un reçu de transaction'
            ],
            [
                'module' => 'Transactions',
                'name' => 'Valider transaction en attente',
                'slug' => 'transactions.validate_pending',
                'description' => 'Permet de valider les transactions en statut attente'
            ],

            // Finances
            [
                'module' => 'Finances',
                'name' => 'Voir soldes globaux',
                'slug' => 'finances.view_balances',
                'description' => 'Accès aux soldes clients, Pro et trésorerie'
            ],
            [
                'module' => 'Finances',
                'name' => 'Gérer retraits des Pro',
                'slug' => 'finances.manage_withdrawals',
                'description' => 'Gestion des demandes de retrait des revendeurs Pro'
            ],
            [
                'module' => 'Finances',
                'name' => 'Crédit/Débit manuel',
                'slug' => 'finances.manual_credit_debit',
                'description' => 'Opérations manuelles de crédit/débit sur les comptes'
            ],
            [
                'module' => 'Finances',
                'name' => 'Export comptable',
                'slug' => 'finances.export_accounting',
                'description' => 'Génération des exports CSV/PDF comptables'
            ],

            // Revendeurs (MDING Pro)
            [
                'module' => 'Revendeurs',
                'name' => 'Valider nouveaux Pro',
                'slug' => 'pro.validate_new',
                'description' => 'Validation des nouvelles inscriptions revendeurs Pro'
            ],
            [
                'module' => 'Revendeurs',
                'name' => 'Voir transactions Pro',
                'slug' => 'pro.view_transactions',
                'description' => 'Consultation des transactions des revendeurs Pro'
            ],
            [
                'module' => 'Revendeurs',
                'name' => 'Ajuster plafond Pro',
                'slug' => 'pro.adjust_limits',
                'description' => 'Ajustement des plafonds et bonus des revendeurs Pro'
            ],
            [
                'module' => 'Pro recharges',
                'name' => 'Liste de recharges des pros',
                'slug' => 'pro.topups_lists',
                'description' => 'Voir la liste des recharges Pro'
            ],
            [
                'module' => 'Clients',
                'name' => 'Liste de mes clients',
                'slug' => 'pro.client_lists',
                'description' => 'Voir la liste des clients Pro'
            ],

            // Technique & Sécurité
            [
                'module' => 'Technique & Sécurité',
                'name' => 'Gérer clés API',
                'slug' => 'tech.manage_api',
                'description' => 'Gestion des clés API et intégrations (EDG, OM, MoMo...)'
            ],
            [
                'module' => 'Technique & Sécurité',
                'name' => 'Voir journaux erreur',
                'slug' => 'tech.view_error_logs',
                'description' => 'Consultation des journaux d\'erreur et statuts services'
            ],

            // Créances & Crédit
            [
                'module' => 'Créances & Crédit',
                'name' => 'Gérer créances et profils de crédit',
                'slug' => 'credits.manage',
                'description' => 'Accès complet au module créances : créer, valider, rejeter, bloquer, limites, risk dashboard'
            ],

            // Rapports & Pilotage
            [
                'module' => 'Rapports & Pilotage',
                'name' => 'Rapports financiers',
                'slug' => 'reports.financial',
                'description' => 'Accès aux rapports financiers globaux'
            ],
            [
                'module' => 'Rapports & Pilotage',
                'name' => 'Rapports opérationnels',
                'slug' => 'reports.operational',
                'description' => 'Accès aux rapports support, délais, performance'
            ],
            [
                'module' => 'Rapports & Pilotage',
                'name' => 'Dashboard temps réel',
                'slug' => 'reports.realtime_dashboard',
                'description' => 'Accès en lecture au dashboard temps réel'
            ],
        ];

        foreach ($permissions as $permission) {
            \App\Models\Permission::firstOrCreate(
                ['slug' => $permission['slug']],
                $permission
            );
        }

        $this->command->info('Permissions créées avec succès!');
    }
}
