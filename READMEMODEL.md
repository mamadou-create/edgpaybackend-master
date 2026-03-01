# Migrations Laravel pour le système EdgPay - Version commentée

Voici les migrations Laravel avec des commentaires détaillés pour chaque table et colonne :

## 1. Migration de la table `users` (étendue)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Ajoute les colonnes supplémentaires à la table users
     * pour correspondre à la structure de données Firebase.
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Numéro de téléphone de l'utilisateur
            $table->string('phone')->nullable()->after('email');
            
            // Nom d'affichage personnalisé (peut différer du nom légal)
            $table->string('display_name')->nullable()->after('name');
            
            // Indicateur si l'utilisateur a un statut professionnel
            $table->boolean('is_pro')->default(false)->after('remember_token');
            
            // Rôle de l'utilisateur (admin, user, etc.)
            $table->string('role')->default('user')->after('is_pro');
            
            // Devise par défaut de l'utilisateur
            $table->string('currency')->default('GNF')->after('role');
            
            // Date de vérification de l'email
            $table->timestamp('email_verified_at')->nullable()->after('currency');
            
            // Soft delete pour archiver les utilisateurs au lieu de les supprimer
            $table->softDeletes();
        });
    }

    /**
     * Annule la migration : Supprime les colonnes ajoutées
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 
                'display_name', 
                'is_pro', 
                'role', 
                'currency',
                'email_verified_at',
                'deleted_at'
            ]);
        });
    }
};
```

## 2. Migration pour la table `wallets`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des portefeuilles utilisateurs
     * Cette table stocke les soldes des différents types de fonds pour chaque utilisateur
     */
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            // Identifiant unique du portefeuille
            $table->id();
            
            // Clé étrangère vers l'utilisateur propriétaire du portefeuille
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Devise du portefeuille (GNF par défaut)
            $table->string('currency')->default('GNF');
            
            // Solde de trésorerie disponible
            $table->integer('cash_available')->default(0);
            
            // Commission disponible pour retrait
            $table->integer('commission_available')->default(0);
            
            // Solde total des commissions (disponible + non disponible)
            $table->integer('commission_balance')->default(0);
            
            // Fonds flottants pour différents types (EDG, PARTNER, etc.)
            // Stockés sous forme de JSON: {"EDG": 1000, "PARTNER": 500}
            $table->json('floats')->nullable();
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Contrainte d'unicité: un utilisateur ne peut avoir qu'un portefeuille
            $table->unique('user_id');
        });
    }

    /**
     * Annule la migration : Supprime la table des portefeuilles
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
};
```

## 3. Migration pour la table `wallet_transactions`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des transactions de portefeuille
     * Cette table enregistre toutes les opérations financières sur les portefeuilles
     */
    public function up()
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            // Identifiant unique de la transaction
            $table->id();
            
            // Clé étrangère vers le portefeuille concerné
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            
            // Montant de la transaction (peut être positif ou négatif)
            $table->integer('amount');
            
            // Type de transaction: commission, transfer, topup, withdrawal, etc.
            $table->string('type');
            
            // Référence externe pour lier à d'autres entités (ex: vente:123)
            $table->string('reference')->nullable();
            
            // Description textuelle de la transaction
            $table->text('description')->nullable();
            
            // Métadonnées supplémentaires au format JSON
            $table->json('metadata')->nullable();
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Index pour optimiser les requêtes fréquentes
            $table->index(['wallet_id', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Annule la migration : Supprime la table des transactions
     */
    public function down()
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
```

## 4. Migration pour la table `demandes_pro`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des demandes de compte professionnel
     * Cette table gère le processus de demande et d'approbation des comptes pro
     */
    public function up()
    {
        Schema::create('demandes_pro', function (Blueprint $table) {
            // Identifiant unique de la demande
            $table->id();
            
            // Clé étrangère vers l'utilisateur demandeur
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Informations personnelles du demandeur
            $table->string('nom');
            $table->string('prenom');
            $table->string('entreprise');
            $table->string('ville');
            $table->string('quartier');
            
            // Type de pièce d'identité (CNI, Passeport, etc.)
            $table->string('piece_identite');
            
            // Chemin vers l'image de la pièce d'identité
            $table->string('piece_image_path')->nullable();
            
            // Coordonnées de contact
            $table->string('email');
            $table->string('adresse');
            $table->string('telephone');
            
            // Statut de la demande
            $table->enum('status', ['en attente', 'accepté', 'refusé', 'annulé'])->default('en attente');
            
            // Dates importantes du processus
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_decision')->nullable();
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Contrainte d'unicité: un utilisateur ne peut avoir qu'une demande active
            $table->unique('user_id');
            
            // Index pour optimiser les requêtes par statut
            $table->index('status');
        });
    }

    /**
     * Annule la migration : Supprime la table des demandes pro
     */
    public function down()
    {
        Schema::dropIfExists('demandes_pro');
    }
};
```

## 5. Migration pour la table `topup_requests`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des demandes de recharge
     * Cette table gère les demandes de recharge de portefeuille par les pros
     */
    public function up()
    {
        Schema::create('topup_requests', function (Blueprint $table) {
            // Identifiant unique de la demande
            $table->id();
            
            // Clé étrangère vers l'utilisateur pro demandeur
            $table->foreignId('pro_id')->constrained('users')->onDelete('cascade');
            
            // Montant demandé pour la recharge
            $table->integer('amount');
            
            // Type de recharge: CASH, EDG; GSS ou PARTNER
            $table->enum('kind', ['CASH', 'EDG', 'GSS', 'PARTNER']);
            
            // Statut de la demande
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('PENDING');
            
            // Clé d'idempotence pour éviter les doublons
            $table->string('idempotency_key');
            
            // Note facultative sur la demande
            $table->text('note')->nullable();
            
            // Référence vers l'admin qui a pris la décision
            $table->foreignId('decided_by')->nullable()->constrained('users');
            
            // Raison de la décision (approbation ou rejet)
            $table->text('reason')->nullable();
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Index pour optimiser les requêtes fréquentes
            $table->index(['pro_id', 'status']);
            $table->index('idempotency_key');
            $table->index('created_at');
        });
    }

    /**
     * Annule la migration : Supprime la table des demandes de recharge
     */
    public function down()
    {
        Schema::dropIfExists('topup_requests');
    }
};
```

## 6. Migration pour la table `ventes`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des ventes
     * Cette table enregistre toutes les transactions commerciales
     */
    public function up()
    {
        Schema::create('ventes', function (Blueprint $table) {
            // Identifiant unique de la vente
            $table->id();
            
            // Clé étrangère vers le vendeur (utilisateur pro)
            $table->foreignId('vendeur_id')->constrained('users')->onDelete('cascade');
            
            // Montant total de la vente
            $table->decimal('montant', 10, 2);
            
            // Devise de la transaction
            $table->string('currency')->default('GNF');
            
            // Détails supplémentaires sur la vente
            $table->text('details')->nullable();
            
            // Date et heure de la transaction
            $table->timestamp('timestamp');
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Index pour optimiser les requêtes par vendeur et date
            $table->index(['vendeur_id', 'timestamp']);
        });
    }

    /**
     * Annule la migration : Supprime la table des ventes
     */
    public function down()
    {
        Schema::dropIfExists('ventes');
    }
};
```

## 7. Migration pour la table `commissions`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des commissions
     * Cette table enregistre les commissions générées par les ventes
     */
    public function up()
    {
        Schema::create('commissions', function (Blueprint $table) {
            // Identifiant unique de l'entrée de commission
            $table->id();
            
            // Clé étrangère vers l'utilisateur qui reçoit la commission
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Clé étrangère vers la vente qui a généré la commission
            $table->foreignId('vente_id')->constrained()->onDelete('cascade');
            
            // Montant de base sur lequel la commission est calculée
            $table->decimal('base_amount', 10, 2);
            
            // Taux de commission appliqué (ex: 0.05 pour 5%)
            $table->decimal('rate', 5, 2);
            
            // Montant de la commission calculée
            $table->decimal('amount', 10, 2);
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Index pour optimiser les requêtes par utilisateur et date
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Annule la migration : Supprime la table des commissions
     */
    public function down()
    {
        Schema::dropIfExists('commissions');
    }
};
```

## 8. Migration pour la table `commission_settings`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des paramètres de commission
     * Cette table stocke les taux de commission configurables
     */
    public function up()
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            // Identifiant unique du paramètre
            $table->id();
            
            // Taux de commission (5% par défaut)
            $table->decimal('rate', 5, 2)->default(0.05);
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
        });

        // Insérer le taux de commission par défaut
        DB::table('commission_settings')->insert([
            'rate' => 0.05,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Annule la migration : Supprime la table des paramètres de commission
     */
    public function down()
    {
        Schema::dropIfExists('commission_settings');
    }
};
```

## 9. Migration pour la table `admin_operations`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des opérations administratives
     * Cette table enregistre les opérations effectuées par les administrateurs
     */
    public function up()
    {
        Schema::create('admin_operations', function (Blueprint $table) {
            // Identifiant unique de l'opération
            $table->id();
            
            // Clé étrangère vers l'administrateur qui a effectué l'opération
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            
            // Clé étrangère vers l'utilisateur pro affecté par l'opération
            $table->foreignId('pro_id')->constrained('users')->onDelete('cascade');
            
            // Montant de l'opération
            $table->decimal('amount');
            
            // Devise de l'opération
            $table->string('currency')->default('GNF');
            
            // Type d'opération: topup, debit, adjustment, etc.
            $table->string('operation_type');
            
            // Clé d'idempotence pour éviter les doublons
            $table->string('idempotency_key');
            
            // Note facultative sur l'opération
            $table->text('note')->nullable();
            
            // Fournisseur de fonds flottants (EDG, PARTNER, etc.)
            $table->string('provider_for_float')->nullable();
            
            // Identifiant de transaction côté pro
            $table->string('pro_transaction_id')->nullable();
            
            // Identifiant de l'opération (pour référence externe)
            $table->string('operation_id')->nullable();
            
            // Horodatages de création et de mise à jour
            $table->timestamps();
            
            // Index pour optimiser les requêtes fréquentes
            $table->index(['admin_id', 'pro_id']);
            $table->index('idempotency_key');
        });
    }

    /**
     * Annule la migration : Supprime la table des opérations administratives
     */
    public function down()
    {
        Schema::dropIfExists('admin_operations');
    }
};
```

## Instructions d'utilisation

1. Placez ces fichiers dans le dossier `database/migrations` de votre projet Laravel
2. Exécutez les migrations dans l'ordre avec la commande : `php artisan migrate`
3. Assurez-vous d'avoir les modèles Eloquent correspondants pour chaque table

Ces migrations recréent fidèlement la structure de données de votre application Firebase dans une base de données relationnelle, avec des relations claires et des contraintes d'intégrité. Les commentaires détaillés expliquent le rôle de chaque table et colonne dans le système global.





# Migrations Laravel avec des UUID (GUID) pour toutes les tables

Voici les migrations modifiées pour utiliser des UUID (GUID) comme identifiants primaires pour toutes les tables, avec des clés étrangères cohérentes :

## 1. Migration de la table `users` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table users avec UUID comme clé primaire
     * et ajoute les colonnes supplémentaires pour correspondre à Firebase
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            // UUID comme clé primaire au lieu de l'auto-incrément
            $table->uuid('id')->primary();
            
            // Colonnes standards de Laravel
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            
            // Colonnes supplémentaires pour EdgPay
            $table->string('phone')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_pro')->default(false);
            $table->string('role')->default('user');
            $table->string('currency')->default('GNF');
            
            // Horodatages et soft delete
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Annule la migration : Supprime la table users
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
};
```

## 2. Migration pour la table `wallets` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des portefeuilles avec UUID
     */
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Colonnes du portefeuille
            $table->string('currency')->default('GNF');
            $table->double('cash_available')->default(0);
            $table->double('commission_available')->default(0);
            $table->double('commission_balance')->default(0);
            $table->json('floats')->nullable();
            
            // Horodatages
            $table->timestamps();
            
            // Contrainte d'unicité
            $table->unique('user_id');
        });
    }

    /**
     * Annule la migration : Supprime la table des portefeuilles
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
};
```

## 3. Migration pour la table `wallet_transactions` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des transactions de portefeuille avec UUID
     */
    public function up()
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers le portefeuille (UUID)
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            
            // Colonnes de la transaction
            $table->double('amount');
            $table->string('type');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            
            // Horodatages
            $table->timestamps();
            
            // Index
            $table->index(['wallet_id', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Annule la migration : Supprime la table des transactions
     */
    public function down()
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
```

## 4. Migration pour la table `demandes_pro` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des demandes de compte professionnel avec UUID
     */
    public function up()
    {
        Schema::create('demandes_pro', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Informations personnelles
            $table->string('nom');
            $table->string('prenom');
            $table->string('entreprise');
            $table->string('ville');
            $table->string('quartier');
            $table->string('piece_identite');
            $table->string('piece_image_path')->nullable();
            $table->string('email');
            $table->string('adresse');
            $table->string('telephone');
            
            // Statut et dates
            $table->enum('status', ['en attente', 'accepté', 'refusé', 'annulé'])->default('en attente');
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_decision')->nullable();
            
            // Horodatages
            $table->timestamps();
            
            // Contraintes et index
            $table->unique('user_id');
            $table->index('status');
        });
    }

    /**
     * Annule la migration : Supprime la table des demandes pro
     */
    public function down()
    {
        Schema::dropIfExists('demandes_pro');
    }
};
```

## 5. Migration pour la table `topup_requests` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des demandes de recharge avec UUID
     */
    public function up()
    {
        Schema::create('topup_requests', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'utilisateur pro (UUID)
            $table->uuid('pro_id');
            $table->foreign('pro_id')->references('id')->on('users')->onDelete('cascade');
            
            // Clé étrangère vers l'admin décisionnaire (UUID, nullable)
            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users');
            
            // Colonnes de la demande
            $table->double('amount');
            $table->enum('kind', ['CASH', 'EDG', 'PARTNER']);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('PENDING');
            $table->string('idempotency_key');
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            
            // Horodatages
            $table->timestamps();
            
            // Index
            $table->index(['pro_id', 'status']);
            $table->index('idempotency_key');
            $table->index('created_at');
        });
    }

    /**
     * Annule la migration : Supprime la table des demandes de recharge
     */
    public function down()
    {
        Schema::dropIfExists('topup_requests');
    }
};
```

## 6. Migration pour la table `ventes` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des ventes avec UUID
     */
    public function up()
    {
        Schema::create('ventes', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers le vendeur (UUID)
            $table->uuid('vendeur_id');
            $table->foreign('vendeur_id')->references('id')->on('users')->onDelete('cascade');
            
            // Colonnes de la vente
            $table->decimal('montant', 10, 2);
            $table->string('currency')->default('GNF');
            $table->text('details')->nullable();
            $table->timestamp('timestamp');
            
            // Horodatages
            $table->timestamps();
            
            // Index
            $table->index(['vendeur_id', 'timestamp']);
        });
    }

    /**
     * Annule la migration : Supprime la table des ventes
     */
    public function down()
    {
        Schema::dropIfExists('ventes');
    }
};
```

## 7. Migration pour la table `commissions` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des commissions avec UUID
     */
    public function up()
    {
        Schema::create('commissions', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'utilisateur (UUID)
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Clé étrangère vers la vente (UUID)
            $table->uuid('vente_id');
            $table->foreign('vente_id')->references('id')->on('ventes')->onDelete('cascade');
            
            // Colonnes de la commission
            $table->decimal('base_amount', 10, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 10, 2);
            
            // Horodatages
            $table->timestamps();
            
            // Index
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Annule la migration : Supprime la table des commissions
     */
    public function down()
    {
        Schema::dropIfExists('commissions');
    }
};
```

## 8. Migration pour la table `commission_settings` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des paramètres de commission avec UUID
     */
    public function up()
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Colonne du taux de commission
            $table->decimal('rate', 5, 2)->default(0.05);
            
            // Horodatages
            $table->timestamps();
        });

        // Insérer le taux de commission par défaut
        DB::table('commission_settings')->insert([
            'id' => Str::uuid(),
            'rate' => 0.05,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Annule la migration : Supprime la table des paramètres de commission
     */
    public function down()
    {
        Schema::dropIfExists('commission_settings');
    }
};
```

## 9. Migration pour la table `admin_operations` (avec UUID)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécute la migration : Crée la table des opérations administratives avec UUID
     */
    public function up()
    {
        Schema::create('admin_operations', function (Blueprint $table) {
            // UUID comme clé primaire
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'administrateur (UUID)
            $table->uuid('admin_id');
            $table->foreign('admin_id')->references('id')->on('users')->onDelete('cascade');
            
            // Clé étrangère vers l'utilisateur pro (UUID)
            $table->uuid('pro_id');
            $table->foreign('pro_id')->references('id')->on('users')->onDelete('cascade');
            
            // Colonnes de l'opération
            $table->double('amount');
            $table->string('currency')->default('GNF');
            $table->string('operation_type');
            $table->string('idempotency_key');
            $table->text('note')->nullable();
            $table->string('provider_for_float')->nullable();
            $table->string('pro_transaction_id')->nullable();
            $table->string('operation_id')->nullable();
            
            // Horodatages
            $table->timestamps();
            
            // Index
            $table->index(['admin_id', 'pro_id']);
            $table->index('idempotency_key');
        });
    }

    /**
     * Annule la migration : Supprime la table des opérations administratives
     */
    public function down()
    {
        Schema::dropIfExists('admin_operations');
    }
};
```

## Modèles Eloquent avec UUID

Pour chaque modèle, vous devrez utiliser le trait `GeneratesUuid` ou configurer le modèle pour utiliser des UUID :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    // ... reste du modèle
}
```

## Configuration de la base de données

Assurez-vous que votre base de données supporte les UUID. Pour MySQL, vous pouvez utiliser le type `CHAR(36)` ou `BINARY(16)`, mais Laravel utilise par défaut le type `string` qui se traduit par `VARCHAR(255)`.

## Instructions d'utilisation

1. Placez ces fichiers dans le dossier `database/migrations` de votre projet Laravel
2. Exécutez les migrations avec la commande : `php artisan migrate`
3. Configurez vos modèles Eloquent pour utiliser les UUID comme montré ci-dessus

Ces migrations créent une structure de données cohérente avec votre application Firebase, mais en utilisant des UUID comme identifiants primaires pour toutes les tables, ce qui améliore la sécurité et facilite la synchronisation entre différents systèmes.



/usr/local/bin/php /home/c2424206c/public_html/edgpayapi/artisan queue:work --stop-when-empty >/dev/null 2>&1
8,26,42,46


 <!-- c2689220c_mding
 c2689220c_edgpay
Mding@2025Pay -->