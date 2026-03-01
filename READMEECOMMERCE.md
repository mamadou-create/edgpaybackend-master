Je vais vous générer un site e-commerce complet avec Laravel (backend) en utilisant l'architecture Repository pattern comme dans votre exemple. Voici la structure complète avec UUIDs.

## 1. **Migrations**

### `database/migrations/xxxx_create_familles_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        Schema::create('familles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->string('image')->nullable();
            $table->integer('ordre')->default(0);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('familles');
    }
};
```

### `database/migrations/xxxx_create_categories_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->string('image')->nullable();
            $table->uuid('famille_id');
            $table->integer('ordre')->default(0);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('famille_id')
                  ->references('id')
                  ->on('familles')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
};
```

### `database/migrations/xxxx_create_sous_categories_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sous_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->uuid('categorie_id');
            $table->integer('ordre')->default(0);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('categorie_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sous_categories');
    }
};
```

### `database/migrations/xxxx_create_produits_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->string('reference')->unique();
            $table->text('description')->nullable();
            $table->text('description_complete')->nullable();
            $table->decimal('prix', 15, 2);
            $table->decimal('prix_promotion', 15, 2)->nullable();
            $table->integer('quantite')->default(0);
            $table->integer('quantite_alerte')->default(10);
            $table->uuid('sous_categorie_id');
            $table->uuid('vendeur_id'); // Pour multi-vendeurs
            $table->string('marque')->nullable();
            $table->string('modele')->nullable();
            $table->json('specifications')->nullable(); // Spécifications techniques
            $table->json('caracteristiques')->nullable(); // Caractéristiques en JSON
            $table->boolean('est_en_stock')->default(true);
            $table->boolean('est_en_vedette')->default(false);
            $table->boolean('est_nouveau')->default(false);
            $table->boolean('est_actif')->default(true);
            $table->integer('vue_count')->default(0);
            $table->integer('vente_count')->default(0);
            $table->decimal('rating_moyen', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sous_categorie_id')
                  ->references('id')
                  ->on('sous_categories')
                  ->onDelete('cascade');

            $table->foreign('vendeur_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index('reference');
            $table->index('est_en_vedette');
            $table->index('est_nouveau');
        });
    }

    public function down()
    {
        Schema::dropIfExists('produits');
    }
};
```

### `database/migrations/xxxx_create_produit_images_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('produit_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('produit_id');
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->integer('ordre')->default(0);
            $table->boolean('est_principale')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('produit_id')
                  ->references('id')
                  ->on('produits')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('produit_images');
    }
};
```

### `database/migrations/xxxx_create_promotions_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->string('code')->unique()->nullable(); // Pour codes promo
            $table->text('description')->nullable();
            $table->enum('type', ['pourcentage', 'fixe', 'livraison_gratuite']);
            $table->decimal('valeur', 15, 2);
            $table->decimal('minimum_commande', 15, 2)->nullable();
            $table->integer('utilisation_max')->nullable(); // Nombre max d'utilisations
            $table->integer('utilisation_count')->default(0);
            $table->dateTime('date_debut');
            $table->dateTime('date_fin');
            $table->boolean('est_actif')->default(true);
            $table->boolean('pour_tous_produits')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('promotions');
    }
};
```

### `database/migrations/xxxx_create_promotion_produit_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('promotion_produit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('produit_id');
            $table->timestamps();

            $table->foreign('promotion_id')
                  ->references('id')
                  ->on('promotions')
                  ->onDelete('cascade');

            $table->foreign('produit_id')
                  ->references('id')
                  ->on('produits')
                  ->onDelete('cascade');

            $table->unique(['promotion_id', 'produit_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('promotion_produit');
    }
};
```

### `database/migrations/xxxx_create_avis_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('avis', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('produit_id');
            $table->uuid('user_id');
            $table->integer('rating'); // 1-5
            $table->string('titre')->nullable();
            $table->text('commentaire')->nullable();
            $table->json('points_positifs')->nullable();
            $table->json('points_negatifs')->nullable();
            $table->boolean('est_verifie')->default(false); // Achat vérifié
            $table->integer('likes')->default(0);
            $table->integer('dislikes')->default(0);
            $table->boolean('est_approuve')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('produit_id')
                  ->references('id')
                  ->on('produits')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->unique(['produit_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('avis');
    }
};
```

### `database/migrations/xxxx_create_paniers_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('paniers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('session_id')->nullable(); // Pour utilisateurs non connectés
            $table->uuid('promotion_id')->nullable();
            $table->decimal('sous_total', 15, 2)->default(0);
            $table->decimal('reduction', 15, 2)->default(0);
            $table->decimal('frais_livraison', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('promotion_id')
                  ->references('id')
                  ->on('promotions')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('paniers');
    }
};
```

### `database/migrations/xxxx_create_panier_items_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('panier_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('panier_id');
            $table->uuid('produit_id');
            $table->integer('quantite');
            $table->decimal('prix_unitaire', 15, 2);
            $table->decimal('prix_total', 15, 2);
            $table->json('options')->nullable(); // Couleur, taille, etc.
            $table->timestamps();

            $table->foreign('panier_id')
                  ->references('id')
                  ->on('paniers')
                  ->onDelete('cascade');

            $table->foreign('produit_id')
                  ->references('id')
                  ->on('produits')
                  ->onDelete('cascade');

            $table->unique(['panier_id', 'produit_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('panier_items');
    }
};
```

## 2. **Modèles**

### `app/Models/Famille.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Famille extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'nom',
        'description',
        'slug',
        'image',
        'ordre',
        'est_actif'
    ];

    protected $casts = [
        'est_actif' => 'boolean',
    ];

    public function categories()
    {
        return $this->hasMany(Categorie::class);
    }

    public function produits()
    {
        return $this->hasManyThrough(Produit::class, Categorie::class);
    }
}
```

### `app/Models/Categorie.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categorie extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'nom',
        'description',
        'slug',
        'image',
        'famille_id',
        'ordre',
        'est_actif'
    ];

    protected $casts = [
        'est_actif' => 'boolean',
    ];

    public function famille()
    {
        return $this->belongsTo(Famille::class);
    }

    public function sousCategories()
    {
        return $this->hasMany(SousCategorie::class);
    }

    public function produits()
    {
        return $this->hasManyThrough(Produit::class, SousCategorie::class);
    }
}
```

### `app/Models/SousCategorie.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SousCategorie extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'nom',
        'description',
        'slug',
        'categorie_id',
        'ordre',
        'est_actif'
    ];

    protected $casts = [
        'est_actif' => 'boolean',
    ];

    public function categorie()
    {
        return $this->belongsTo(Categorie::class);
    }

    public function produits()
    {
        return $this->hasMany(Produit::class);
    }
}
```

### `app/Models/Produit.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produit extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'nom',
        'reference',
        'description',
        'description_complete',
        'prix',
        'prix_promotion',
        'quantite',
        'quantite_alerte',
        'sous_categorie_id',
        'vendeur_id',
        'marque',
        'modele',
        'specifications',
        'caracteristiques',
        'est_en_stock',
        'est_en_vedette',
        'est_nouveau',
        'est_actif',
        'vue_count',
        'vente_count',
        'rating_moyen',
        'rating_count'
    ];

    protected $casts = [
        'specifications' => 'array',
        'caracteristiques' => 'array',
        'est_en_stock' => 'boolean',
        'est_en_vedette' => 'boolean',
        'est_nouveau' => 'boolean',
        'est_actif' => 'boolean',
        'prix' => 'decimal:2',
        'prix_promotion' => 'decimal:2',
        'rating_moyen' => 'decimal:2',
    ];

    protected $appends = ['prix_final', 'en_promotion'];

    public function sousCategorie()
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function vendeur()
    {
        return $this->belongsTo(User::class, 'vendeur_id');
    }

    public function images()
    {
        return $this->hasMany(ProduitImage::class);
    }

    public function promotions()
    {
        return $this->belongsToMany(Promotion::class, 'promotion_produit')
                    ->where('est_actif', true)
                    ->where('date_debut', '<=', now())
                    ->where('date_fin', '>=', now());
    }

    public function avis()
    {
        return $this->hasMany(Avis::class)->where('est_approuve', true);
    }

    public function panierItems()
    {
        return $this->hasMany(PanierItem::class);
    }

    public function getPrixFinalAttribute()
    {
        $prix = $this->prix_promotion ?: $this->prix;
        
        // Appliquer les promotions actives
        if ($this->promotions->isNotEmpty()) {
            foreach ($this->promotions as $promotion) {
                if ($promotion->type === 'pourcentage') {
                    $prix = $prix * (1 - $promotion->valeur / 100);
                } elseif ($promotion->type === 'fixe') {
                    $prix = max(0, $prix - $promotion->valeur);
                }
            }
        }
        
        return round($prix, 2);
    }

    public function getEnPromotionAttribute()
    {
        return $this->prix_promotion !== null || $this->promotions->isNotEmpty();
    }

    public function getPourcentageReductionAttribute()
    {
        if ($this->prix_promotion) {
            return round((($this->prix - $this->prix_promotion) / $this->prix) * 100);
        }
        return 0;
    }

    public function incrementVues()
    {
        $this->increment('vue_count');
    }

    public function incrementVentes($quantite = 1)
    {
        $this->increment('vente_count', $quantite);
        $this->decrement('quantite', $quantite);
        
        if ($this->quantite <= $this->quantite_alerte) {
            $this->est_en_stock = false;
            $this->save();
        }
    }
}
```

### `app/Models/ProduitImage.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProduitImage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'produit_id',
        'url',
        'thumbnail_url',
        'ordre',
        'est_principale',
        'description'
    ];

    protected $casts = [
        'est_principale' => 'boolean',
    ];

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }
}
```

### `app/Models/Promotion.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'nom',
        'code',
        'description',
        'type',
        'valeur',
        'minimum_commande',
        'utilisation_max',
        'utilisation_count',
        'date_debut',
        'date_fin',
        'est_actif',
        'pour_tous_produits'
    ];

    protected $casts = [
        'est_actif' => 'boolean',
        'pour_tous_produits' => 'boolean',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'valeur' => 'decimal:2',
        'minimum_commande' => 'decimal:2',
    ];

    public function produits()
    {
        return $this->belongsToMany(Produit::class, 'promotion_produit');
    }

    public function paniers()
    {
        return $this->hasMany(Panier::class);
    }

    public function estValide()
    {
        return $this->est_actif && 
               now()->between($this->date_debut, $this->date_fin) &&
               ($this->utilisation_max === null || $this->utilisation_count < $this->utilisation_max);
    }

    public function incrementUtilisation()
    {
        $this->increment('utilisation_count');
    }
}
```

### `app/Models/Avis.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Avis extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'produit_id',
        'user_id',
        'rating',
        'titre',
        'commentaire',
        'points_positifs',
        'points_negatifs',
        'est_verifie',
        'likes',
        'dislikes',
        'est_approuve'
    ];

    protected $casts = [
        'est_verifie' => 'boolean',
        'est_approuve' => 'boolean',
        'points_positifs' => 'array',
        'points_negatifs' => 'array',
    ];

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function incrementLikes()
    {
        $this->increment('likes');
    }

    public function incrementDislikes()
    {
        $this->increment('dislikes');
    }
}
```

### `app/Models/Panier.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Panier extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'session_id',
        'promotion_id',
        'sous_total',
        'reduction',
        'frais_livraison',
        'total'
    ];

    protected $casts = [
        'sous_total' => 'decimal:2',
        'reduction' => 'decimal:2',
        'frais_livraison' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function items()
    {
        return $this->hasMany(PanierItem::class);
    }

    public function calculerTotaux()
    {
        $sousTotal = $this->items->sum(function ($item) {
            return $item->prix_total;
        });

        $reduction = 0;
        
        if ($this->promotion && $this->promotion->estValide()) {
            if ($sousTotal >= $this->promotion->minimum_commande) {
                if ($this->promotion->type === 'pourcentage') {
                    $reduction = $sousTotal * ($this->promotion->valeur / 100);
                } elseif ($this->promotion->type === 'fixe') {
                    $reduction = $this->promotion->valeur;
                } elseif ($this->promotion->type === 'livraison_gratuite') {
                    $reduction = $this->frais_livraison;
                }
            }
        }

        $total = $sousTotal - $reduction + $this->frais_livraison;

        $this->update([
            'sous_total' => $sousTotal,
            'reduction' => $reduction,
            'total' => max(0, $total)
        ]);
    }

    public function viderPanier()
    {
        $this->items()->delete();
        $this->calculerTotaux();
    }
}
```

### `app/Models/PanierItem.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PanierItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'panier_id',
        'produit_id',
        'quantite',
        'prix_unitaire',
        'prix_total',
        'options'
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
        'prix_total' => 'decimal:2',
        'options' => 'array',
    ];

    public function panier()
    {
        return $this->belongsTo(Panier::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }
}
```

## 3. **Interfaces Repository**

### `app/Interfaces/FamilleRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface FamilleRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getActives();
    public function search($keyword);
}
```

### `app/Interfaces/CategorieRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface CategorieRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getByFamille($familleId);
    public function getActives();
}
```

### `app/Interfaces/SousCategorieRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface SousCategorieRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getByCategorie($categorieId);
}
```

### `app/Interfaces/ProduitRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface ProduitRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getEnVedette($limit = 10);
    public function getNouveaux($limit = 10);
    public function getEnPromotion($limit = 10);
    public function search($filters);
    public function getBySousCategorie($sousCategorieId);
    public function getByCategorie($categorieId);
    public function getByFamille($familleId);
    public function incrementVues($id);
    public function getSimilaires($produitId, $limit = 5);
}
```

### `app/Interfaces/PromotionRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface PromotionRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getActives();
    public function getByCode($code);
    public function verifierCode($code, $montantPanier);
}
```

### `app/Interfaces/AvisRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface AvisRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function getByProduit($produitId);
    public function getByUser($userId);
    public function incrementLikes($id);
    public function incrementDislikes($id);
    public function getMoyenneRating($produitId);
}
```

### `app/Interfaces/PanierRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface PanierRepositoryInterface
{
    public function getByUser($userId);
    public function getBySession($sessionId);
    public function createOrGet($userId, $sessionId);
    public function ajouterItem($panierId, array $itemData);
    public function mettreAJourItem($itemId, array $data);
    public function supprimerItem($itemId);
    public function viderPanier($panierId);
    public function appliquerPromotion($panierId, $codePromo);
    public function retirerPromotion($panierId);
}
```

## 4. **Repositories**

### `app/Repositories/FamilleRepository.php`
```php
<?php

namespace App\Repositories;

use App\Interfaces\FamilleRepositoryInterface;
use App\Models\Famille;
use Illuminate\Support\Str;

class FamilleRepository implements FamilleRepositoryInterface
{
    public function getAll()
    {
        return Famille::orderBy('ordre')->get();
    }

    public function getByID($id)
    {
        return Famille::find($id);
    }

    public function create(array $data)
    {
        $data['slug'] = Str::slug($data['nom']);
        return Famille::create($data);
    }

    public function update($id, array $data)
    {
        $famille = Famille::find($id);
        if ($famille) {
            if (isset($data['nom'])) {
                $data['slug'] = Str::slug($data['nom']);
            }
            $famille->update($data);
            return $famille;
        }
        return null;
    }

    public function delete($id)
    {
        $famille = Famille::find($id);
        if ($famille) {
            return $famille->delete();
        }
        return false;
    }

    public function getActives()
    {
        return Famille::where('est_actif', true)
                     ->orderBy('ordre')
                     ->get();
    }

    public function search($keyword)
    {
        return Famille::where('nom', 'LIKE', "%{$keyword}%")
                     ->orWhere('description', 'LIKE', "%{$keyword}%")
                     ->orderBy('ordre')
                     ->get();
    }
}
```

### `app/Repositories/ProduitRepository.php`
```php
<?php

namespace App\Repositories;

use App\Interfaces\ProduitRepositoryInterface;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;

class ProduitRepository implements ProduitRepositoryInterface
{
    public function getAll()
    {
        return Produit::with(['sousCategorie.categorie.famille', 'images', 'avis'])
                     ->where('est_actif', true)
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    public function getByID($id)
    {
        return Produit::with(['sousCategorie.categorie.famille', 'images', 'avis', 'promotions'])
                     ->find($id);
    }

    public function create(array $data)
    {
        return Produit::create($data);
    }

    public function update($id, array $data)
    {
        $produit = Produit::find($id);
        if ($produit) {
            $produit->update($data);
            return $produit;
        }
        return null;
    }

    public function delete($id)
    {
        $produit = Produit::find($id);
        if ($produit) {
            return $produit->delete();
        }
        return false;
    }

    public function getEnVedette($limit = 10)
    {
        return Produit::with(['images'])
                     ->where('est_actif', true)
                     ->where('est_en_vedette', true)
                     ->where('est_en_stock', true)
                     ->orderBy('created_at', 'desc')
                     ->limit($limit)
                     ->get();
    }

    public function getNouveaux($limit = 10)
    {
        return Produit::with(['images'])
                     ->where('est_actif', true)
                     ->where('est_nouveau', true)
                     ->where('est_en_stock', true)
                     ->orderBy('created_at', 'desc')
                     ->limit($limit)
                     ->get();
    }

    public function getEnPromotion($limit = 10)
    {
        return Produit::with(['images'])
                     ->where('est_actif', true)
                     ->where('est_en_stock', true)
                     ->where(function ($query) {
                         $query->whereNotNull('prix_promotion')
                               ->orWhereHas('promotions', function ($q) {
                                   $q->where('est_actif', true)
                                     ->where('date_debut', '<=', now())
                                     ->where('date_fin', '>=', now());
                               });
                     })
                     ->orderBy('created_at', 'desc')
                     ->limit($limit)
                     ->get();
    }

    public function search($filters)
    {
        $query = Produit::with(['images', 'sousCategorie.categorie'])
                       ->where('est_actif', true);

        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('nom', 'LIKE', "%{$keyword}%")
                  ->orWhere('description', 'LIKE', "%{$keyword}%")
                  ->orWhere('reference', 'LIKE', "%{$keyword}%");
            });
        }

        if (isset($filters['sous_categorie_id']) && $filters['sous_categorie_id']) {
            $query->where('sous_categorie_id', $filters['sous_categorie_id']);
        }

        if (isset($filters['categorie_id']) && $filters['categorie_id']) {
            $query->whereHas('sousCategorie', function ($q) use ($filters) {
                $q->where('categorie_id', $filters['categorie_id']);
            });
        }

        if (isset($filters['famille_id']) && $filters['famille_id']) {
            $query->whereHas('sousCategorie.categorie', function ($q) use ($filters) {
                $q->where('famille_id', $filters['famille_id']);
            });
        }

        if (isset($filters['prix_min']) && $filters['prix_min']) {
            $query->where('prix', '>=', $filters['prix_min']);
        }

        if (isset($filters['prix_max']) && $filters['prix_max']) {
            $query->where('prix', '<=', $filters['prix_max']);
        }

        if (isset($filters['en_stock']) && $filters['en_stock']) {
            $query->where('est_en_stock', true);
        }

        if (isset($filters['en_promotion']) && $filters['en_promotion']) {
            $query->where(function ($q) {
                $q->whereNotNull('prix_promotion')
                  ->orWhereHas('promotions', function ($q2) {
                      $q2->where('est_actif', true)
                         ->where('date_debut', '<=', now())
                         ->where('date_fin', '>=', now());
                  });
            });
        }

        // Tri
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $filters['per_page'] ?? 20;
        
        return $query->paginate($perPage);
    }

    public function getBySousCategorie($sousCategorieId)
    {
        return Produit::with(['images'])
                     ->where('sous_categorie_id', $sousCategorieId)
                     ->where('est_actif', true)
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    public function getByCategorie($categorieId)
    {
        return Produit::with(['images'])
                     ->whereHas('sousCategorie', function ($query) use ($categorieId) {
                         $query->where('categorie_id', $categorieId);
                     })
                     ->where('est_actif', true)
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    public function getByFamille($familleId)
    {
        return Produit::with(['images'])
                     ->whereHas('sousCategorie.categorie', function ($query) use ($familleId) {
                         $query->where('famille_id', $familleId);
                     })
                     ->where('est_actif', true)
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    public function incrementVues($id)
    {
        $produit = Produit::find($id);
        if ($produit) {
            $produit->increment('vue_count');
            return $produit->vue_count;
        }
        return null;
    }

    public function getSimilaires($produitId, $limit = 5)
    {
        $produit = Produit::find($produitId);
        if (!$produit) {
            return collect();
        }

        return Produit::with(['images'])
                     ->where('sous_categorie_id', $produit->sous_categorie_id)
                     ->where('id', '!=', $produitId)
                     ->where('est_actif', true)
                     ->where('est_en_stock', true)
                     ->orderBy('vente_count', 'desc')
                     ->limit($limit)
                     ->get();
    }
}
```

### `app/Repositories/PanierRepository.php`
```php
<?php

namespace App\Repositories;

use App\Interfaces\PanierRepositoryInterface;
use App\Models\Panier;
use App\Models\PanierItem;
use App\Models\Produit;
use App\Models\Promotion;

class PanierRepository implements PanierRepositoryInterface
{
    public function getByUser($userId)
    {
        return Panier::with(['items.produit.images', 'promotion'])
                    ->where('user_id', $userId)
                    ->first();
    }

    public function getBySession($sessionId)
    {
        return Panier::with(['items.produit.images', 'promotion'])
                    ->where('session_id', $sessionId)
                    ->first();
    }

    public function createOrGet($userId, $sessionId = null)
    {
        $panier = null;
        
        if ($userId) {
            $panier = Panier::where('user_id', $userId)->first();
        } elseif ($sessionId) {
            $panier = Panier::where('session_id', $sessionId)->first();
        }

        if (!$panier) {
            $panier = Panier::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
            ]);
        }

        return $panier;
    }

    public function ajouterItem($panierId, array $itemData)
    {
        $panier = Panier::find($panierId);
        if (!$panier) {
            return null;
        }

        $produit = Produit::find($itemData['produit_id']);
        if (!$produit || !$produit->est_actif || !$produit->est_en_stock) {
            return null;
        }

        // Vérifier si le produit est déjà dans le panier
        $existingItem = PanierItem::where('panier_id', $panierId)
                                 ->where('produit_id', $itemData['produit_id'])
                                 ->first();

        if ($existingItem) {
            // Mettre à jour la quantité
            $existingItem->quantite += $itemData['quantite'] ?? 1;
            $existingItem->prix_total = $existingItem->quantite * $existingItem->prix_unitaire;
            $existingItem->save();
        } else {
            // Ajouter un nouvel item
            PanierItem::create([
                'panier_id' => $panierId,
                'produit_id' => $itemData['produit_id'],
                'quantite' => $itemData['quantite'] ?? 1,
                'prix_unitaire' => $produit->prix_final,
                'prix_total' => ($itemData['quantite'] ?? 1) * $produit->prix_final,
                'options' => $itemData['options'] ?? null,
            ]);
        }

        $panier->calculerTotaux();
        
        return Panier::with(['items.produit.images'])->find($panierId);
    }

    public function mettreAJourItem($itemId, array $data)
    {
        $item = PanierItem::find($itemId);
        if (!$item) {
            return null;
        }

        if (isset($data['quantite'])) {
            if ($data['quantite'] <= 0) {
                return $this->supprimerItem($itemId);
            }

            $produit = $item->produit;
            if ($data['quantite'] > $produit->quantite) {
                return null; // Quantité non disponible
            }

            $item->quantite = $data['quantite'];
            $item->prix_total = $data['quantite'] * $item->prix_unitaire;
            $item->save();

            $item->panier->calculerTotaux();
        }

        return $item;
    }

    public function supprimerItem($itemId)
    {
        $item = PanierItem::find($itemId);
        if (!$item) {
            return false;
        }

        $panierId = $item->panier_id;
        $item->delete();

        $panier = Panier::find($panierId);
        if ($panier) {
            $panier->calculerTotaux();
        }

        return true;
    }

    public function viderPanier($panierId)
    {
        $panier = Panier::find($panierId);
        if ($panier) {
            $panier->items()->delete();
            $panier->calculerTotaux();
            return true;
        }
        return false;
    }

    public function appliquerPromotion($panierId, $codePromo)
    {
        $panier = Panier::with(['items'])->find($panierId);
        if (!$panier) {
            return null;
        }

        $promotion = Promotion::where('code', $codePromo)
                             ->where('est_actif', true)
                             ->first();

        if (!$promotion || !$promotion->estValide()) {
            return null;
        }

        // Vérifier le minimum de commande
        if ($promotion->minimum_commande && $panier->sous_total < $promotion->minimum_commande) {
            return null;
        }

        $panier->promotion_id = $promotion->id;
        $panier->save();
        
        $panier->calculerTotaux();

        return $panier;
    }

    public function retirerPromotion($panierId)
    {
        $panier = Panier::find($panierId);
        if ($panier) {
            $panier->promotion_id = null;
            $panier->save();
            $panier->calculerTotaux();
            return $panier;
        }
        return null;
    }
}
```

## 5. **Controllers**

### `app/Http/Controllers/API/FamilleController.php`
```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\FamilleRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Famille\StoreFamilleRequest;
use App\Http\Requests\Famille\UpdateFamilleRequest;
use App\Http\Resources\FamilleResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilleController extends Controller
{
    private FamilleRepositoryInterface $familleRepository;

    public function __construct(FamilleRepositoryInterface $familleRepository)
    {
        $this->familleRepository = $familleRepository;
    }

    public function index()
    {
        try {
            $familles = $this->familleRepository->getAll();
            return ApiResponseClass::sendResponse(
                FamilleResource::collection($familles),
                'Familles récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des familles');
        }
    }

    public function show($id)
    {
        try {
            $famille = $this->familleRepository->getByID($id);
            if (!$famille) {
                return ApiResponseClass::notFound('Famille introuvable');
            }

            return ApiResponseClass::sendResponse(
                new FamilleResource($famille),
                'Famille récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération de la famille');
        }
    }

    public function store(StoreFamilleRequest $request)
    {
        DB::beginTransaction();
        try {
            $famille = $this->familleRepository->create($request->validated());

            DB::commit();
            return ApiResponseClass::created(
                new FamilleResource($famille),
                'Famille créée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de la famille");
        }
    }

    public function update($id, UpdateFamilleRequest $request)
    {
        DB::beginTransaction();
        try {
            $famille = $this->familleRepository->update($id, $request->validated());

            if (!$famille) {
                DB::rollBack();
                return ApiResponseClass::notFound('Famille introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new FamilleResource($famille),
                'Famille mise à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour de la famille");
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->familleRepository->delete($id);

            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Famille introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Famille supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de la famille");
        }
    }

    public function actives()
    {
        try {
            $familles = $this->familleRepository->getActives();
            return ApiResponseClass::sendResponse(
                FamilleResource::collection($familles),
                'Familles actives récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des familles actives');
        }
    }

    public function search(Request $request)
    {
        try {
            $keyword = $request->input('keyword');
            $familles = $this->familleRepository->search($keyword);
            
            return ApiResponseClass::sendResponse(
                FamilleResource::collection($familles),
                'Résultats de recherche récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la recherche des familles');
        }
    }
}
```

### `app/Http/Controllers/API/ProduitController.php`
```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ProduitRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Produit\StoreProduitRequest;
use App\Http\Requests\Produit\UpdateProduitRequest;
use App\Http\Resources\ProduitResource;
use App\Http\Resources\ProduitDetailResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProduitController extends Controller
{
    private ProduitRepositoryInterface $produitRepository;

    public function __construct(ProduitRepositoryInterface $produitRepository)
    {
        $this->produitRepository = $produitRepository;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->all();
            $produits = $this->produitRepository->search($filters);
            
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits récupérés avec succès',
                [
                    'current_page' => $produits->currentPage(),
                    'total_pages' => $produits->lastPage(),
                    'total_items' => $produits->total(),
                    'per_page' => $produits->perPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits');
        }
    }

    public function show($id)
    {
        try {
            $produit = $this->produitRepository->getByID($id);
            if (!$produit) {
                return ApiResponseClass::notFound('Produit introuvable');
            }

            // Incrémenter le compteur de vues
            $this->produitRepository->incrementVues($id);

            return ApiResponseClass::sendResponse(
                new ProduitDetailResource($produit),
                'Produit récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du produit');
        }
    }

    public function store(StoreProduitRequest $request)
    {
        DB::beginTransaction();
        try {
            $produit = $this->produitRepository->create($request->validated());

            // Gérer les images
            if ($request->has('images')) {
                foreach ($request->images as $imageData) {
                    $produit->images()->create($imageData);
                }
            }

            DB::commit();
            return ApiResponseClass::created(
                new ProduitResource($produit),
                'Produit créé avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création du produit");
        }
    }

    public function update($id, UpdateProduitRequest $request)
    {
        DB::beginTransaction();
        try {
            $produit = $this->produitRepository->update($id, $request->validated());

            if (!$produit) {
                DB::rollBack();
                return ApiResponseClass::notFound('Produit introuvable');
            }

            // Mettre à jour les images si fournies
            if ($request->has('images')) {
                $produit->images()->delete();
                foreach ($request->images as $imageData) {
                    $produit->images()->create($imageData);
                }
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new ProduitResource($produit),
                'Produit mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du produit");
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->produitRepository->delete($id);

            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Produit introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Produit supprimé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression du produit");
        }
    }

    public function enVedette()
    {
        try {
            $produits = $this->produitRepository->getEnVedette();
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits en vedette récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits en vedette');
        }
    }

    public function nouveaux()
    {
        try {
            $produits = $this->produitRepository->getNouveaux();
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Nouveaux produits récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des nouveaux produits');
        }
    }

    public function enPromotion()
    {
        try {
            $produits = $this->produitRepository->getEnPromotion();
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits en promotion récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits en promotion');
        }
    }

    public function similaires($id)
    {
        try {
            $produits = $this->produitRepository->getSimilaires($id);
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits similaires récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits similaires');
        }
    }

    public function bySousCategorie($sousCategorieId)
    {
        try {
            $produits = $this->produitRepository->getBySousCategorie($sousCategorieId);
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits par sous-catégorie récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits par sous-catégorie');
        }
    }

    public function byCategorie($categorieId)
    {
        try {
            $produits = $this->produitRepository->getByCategorie($categorieId);
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits par catégorie récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits par catégorie');
        }
    }

    public function byFamille($familleId)
    {
        try {
            $produits = $this->produitRepository->getByFamille($familleId);
            return ApiResponseClass::sendResponse(
                ProduitResource::collection($produits),
                'Produits par famille récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des produits par famille');
        }
    }
}
```

### `app/Http/Controllers/API/PanierController.php`
```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PanierRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Panier\AjouterItemRequest;
use App\Http\Requests\Panier\UpdateItemRequest;
use App\Http\Resources\PanierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PanierController extends Controller
{
    private PanierRepositoryInterface $panierRepository;

    public function __construct(PanierRepositoryInterface $panierRepository)
    {
        $this->panierRepository = $panierRepository;
    }

    public function index(Request $request)
    {
        try {
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Panier récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du panier');
        }
    }

    public function ajouterItem(AjouterItemRequest $request)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            $panier = $this->panierRepository->ajouterItem($panier->id, $request->validated());
            
            if (!$panier) {
                DB::rollBack();
                return ApiResponseClass::error('Impossible d\'ajouter l\'article au panier', 400);
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Article ajouté au panier avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de l'ajout de l'article au panier");
        }
    }

    public function mettreAJourItem($itemId, UpdateItemRequest $request)
    {
        DB::beginTransaction();
        try {
            $item = $this->panierRepository->mettreAJourItem($itemId, $request->validated());
            
            if (!$item) {
                DB::rollBack();
                return ApiResponseClass::notFound('Article introuvable ou quantité non disponible');
            }

            DB::commit();
            
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Article mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour de l'article");
        }
    }

    public function supprimerItem($itemId, Request $request)
    {
        DB::beginTransaction();
        try {
            $success = $this->panierRepository->supprimerItem($itemId);
            
            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Article introuvable');
            }

            DB::commit();
            
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Article supprimé du panier avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de l'article");
        }
    }

    public function viderPanier(Request $request)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            $success = $this->panierRepository->viderPanier($panier->id);
            
            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::error('Impossible de vider le panier', 400);
            }

            DB::commit();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Panier vidé avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors du vidage du panier");
        }
    }

    public function appliquerPromotion(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'code' => 'required|string',
            ]);

            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            $panier = $this->panierRepository->appliquerPromotion($panier->id, $request->code);
            
            if (!$panier) {
                DB::rollBack();
                return ApiResponseClass::error('Code promotionnel invalide ou expiré', 400);
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Promotion appliquée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de l'application de la promotion");
        }
    }

    public function retirerPromotion(Request $request)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            
            $panier = $this->panierRepository->retirerPromotion($panier->id);
            
            if (!$panier) {
                DB::rollBack();
                return ApiResponseClass::error('Impossible de retirer la promotion', 400);
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new PanierResource($panier),
                'Promotion retirée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors du retrait de la promotion");
        }
    }

    public function nombreArticles(Request $request)
    {
        try {
            $userId = auth()->id();
            $sessionId = $request->session()->getId();
            
            $panier = $this->panierRepository->createOrGet($userId, $sessionId);
            $nombreArticles = $panier->items->sum('quantite');
            
            return ApiResponseClass::sendResponse(
                ['nombre_articles' => $nombreArticles],
                'Nombre d\'articles récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du nombre d\'articles');
        }
    }
}
```

## 6. **Resources**

### `app/Http/Resources/FamilleResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FamilleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'slug' => $this->slug,
            'image' => $this->image,
            'ordre' => $this->ordre,
            'est_actif' => $this->est_actif,
            'categories_count' => $this->whenCounted('categories'),
            'produits_count' => $this->whenCounted('produits'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'categories' => CategorieResource::collection($this->whenLoaded('categories')),
        ];
    }
}
```

### `app/Http/Resources/ProduitResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'reference' => $this->reference,
            'description' => $this->description,
            'prix' => $this->prix,
            'prix_promotion' => $this->prix_promotion,
            'prix_final' => $this->prix_final,
            'en_promotion' => $this->en_promotion,
            'pourcentage_reduction' => $this->pourcentage_reduction,
            'quantite' => $this->quantite,
            'est_en_stock' => $this->est_en_stock,
            'est_en_vedette' => $this->est_en_vedette,
            'est_nouveau' => $this->est_nouveau,
            'marque' => $this->marque,
            'modele' => $this->modele,
            'rating_moyen' => $this->rating_moyen,
            'rating_count' => $this->rating_count,
            'vue_count' => $this->vue_count,
            'vente_count' => $this->vente_count,
            'sous_categorie' => new SousCategorieResource($this->whenLoaded('sousCategorie')),
            'vendeur' => new UserResource($this->whenLoaded('vendeur')),
            'images' => ProduitImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/ProduitDetailResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProduitDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'reference' => $this->reference,
            'description' => $this->description,
            'description_complete' => $this->description_complete,
            'prix' => $this->prix,
            'prix_promotion' => $this->prix_promotion,
            'prix_final' => $this->prix_final,
            'en_promotion' => $this->en_promotion,
            'pourcentage_reduction' => $this->pourcentage_reduction,
            'quantite' => $this->quantite,
            'quantite_alerte' => $this->quantite_alerte,
            'est_en_stock' => $this->est_en_stock,
            'est_en_vedette' => $this->est_en_vedette,
            'est_nouveau' => $this->est_nouveau,
            'marque' => $this->marque,
            'modele' => $this->modele,
            'specifications' => $this->specifications,
            'caracteristiques' => $this->caracteristiques,
            'rating_moyen' => $this->rating_moyen,
            'rating_count' => $this->rating_count,
            'vue_count' => $this->vue_count,
            'vente_count' => $this->vente_count,
            'sous_categorie' => new SousCategorieResource($this->whenLoaded('sousCategorie')),
            'vendeur' => new UserResource($this->whenLoaded('vendeur')),
            'images' => ProduitImageResource::collection($this->whenLoaded('images')),
            'avis' => AvisResource::collection($this->whenLoaded('avis')),
            'promotions' => PromotionResource::collection($this->whenLoaded('promotions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/PanierResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PanierResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'sous_total' => $this->sous_total,
            'reduction' => $this->reduction,
            'frais_livraison' => $this->frais_livraison,
            'total' => $this->total,
            'nombre_articles' => $this->items->sum('quantite'),
            'promotion' => new PromotionResource($this->whenLoaded('promotion')),
            'items' => PanierItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/PanierItemResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PanierItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'prix_total' => $this->prix_total,
            'options' => $this->options,
            'produit' => new ProduitResource($this->whenLoaded('produit')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## 7. **Form Requests**

### `app/Http/Requests/Famille/StoreFamilleRequest.php`
```php
<?php

namespace App\Http\Requests\Famille;

use Illuminate\Foundation\Http\FormRequest;

class StoreFamilleRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'ordre' => 'integer|min:0',
            'est_actif' => 'boolean',
        ];
    }
}
```

### `app/Http/Requests/Produit/StoreProduitRequest.php`
```php
<?php

namespace App\Http\Requests\Produit;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduitRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'nom' => 'required|string|max:255',
            'reference' => 'required|string|max:100|unique:produits,reference',
            'description' => 'required|string',
            'description_complete' => 'nullable|string',
            'prix' => 'required|numeric|min:0',
            'prix_promotion' => 'nullable|numeric|min:0',
            'quantite' => 'required|integer|min:0',
            'quantite_alerte' => 'integer|min:0',
            'sous_categorie_id' => 'required|uuid|exists:sous_categories,id',
            'vendeur_id' => 'required|uuid|exists:users,id',
            'marque' => 'nullable|string|max:100',
            'modele' => 'nullable|string|max:100',
            'specifications' => 'nullable|array',
            'caracteristiques' => 'nullable|array',
            'est_en_stock' => 'boolean',
            'est_en_vedette' => 'boolean',
            'est_nouveau' => 'boolean',
            'est_actif' => 'boolean',
            'images' => 'nullable|array',
            'images.*.url' => 'required|string',
            'images.*.thumbnail_url' => 'nullable|string',
            'images.*.ordre' => 'integer|min:0',
            'images.*.est_principale' => 'boolean',
            'images.*.description' => 'nullable|string',
        ];
    }
}
```

### `app/Http/Requests/Panier/AjouterItemRequest.php`
```php
<?php

namespace App\Http\Requests\Panier;

use Illuminate\Foundation\Http\FormRequest;

class AjouterItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'produit_id' => 'required|uuid|exists:produits,id',
            'quantite' => 'required|integer|min:1',
            'options' => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'produit_id.required' => 'Le produit est requis',
            'produit_id.exists' => 'Le produit sélectionné n\'existe pas',
            'quantite.required' => 'La quantité est requise',
            'quantite.min' => 'La quantité doit être au moins de 1',
        ];
    }
}
```

## 8. **Routes API**

### `routes/api.php`
```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\FamilleController;
use App\Http\Controllers\API\CategorieController;
use App\Http\Controllers\API\SousCategorieController;
use App\Http\Controllers\API\ProduitController;
use App\Http\Controllers\API\PromotionController;
use App\Http\Controllers\API\AvisController;
use App\Http\Controllers\API\PanierController;

// Familles
Route::apiResource('familles', FamilleController::class);
Route::get('familles/{id}/activer', [FamilleController::class, 'activer']);
Route::get('familles/{id}/desactiver', [FamilleController::class, 'desactiver']);

// Catégories
Route::apiResource('categories', CategorieController::class);
Route::get('familles/{familleId}/categories', [CategorieController::class, 'byFamille']);

// Sous-catégories
Route::apiResource('sous-categories', SousCategorieController::class);
Route::get('categories/{categorieId}/sous-categories', [SousCategorieController::class, 'byCategorie']);

// Produits
Route::apiResource('produits', ProduitController::class);
Route::get('produits/en-vedette', [ProduitController::class, 'enVedette']);
Route::get('produits/nouveaux', [ProduitController::class, 'nouveaux']);
Route::get('produits/en-promotion', [ProduitController::class, 'enPromotion']);
Route::get('produits/{id}/similaires', [ProduitController::class, 'similaires']);
Route::get('sous-categories/{sousCategorieId}/produits', [ProduitController::class, 'bySousCategorie']);
Route::get('categories/{categorieId}/produits', [ProduitController::class, 'byCategorie']);
Route::get('familles/{familleId}/produits', [ProduitController::class, 'byFamille']);

// Promotions
Route::apiResource('promotions', PromotionController::class);
Route::get('promotions/actives', [PromotionController::class, 'actives']);
Route::post('promotions/verifier', [PromotionController::class, 'verifier']);

// Avis
Route::apiResource('avis', AvisController::class);
Route::get('produits/{produitId}/avis', [AvisController::class, 'byProduit']);
Route::post('avis/{id}/like', [AvisController::class, 'like']);
Route::post('avis/{id}/dislike', [AvisController::class, 'dislike']);

// Panier
Route::prefix('panier')->group(function () {
    Route::get('/', [PanierController::class, 'index']);
    Route::post('/ajouter', [PanierController::class, 'ajouterItem']);
    Route::put('/items/{itemId}', [PanierController::class, 'mettreAJourItem']);
    Route::delete('/items/{itemId}', [PanierController::class, 'supprimerItem']);
    Route::delete('/vider', [PanierController::class, 'viderPanier']);
    Route::post('/appliquer-promotion', [PanierController::class, 'appliquerPromotion']);
    Route::delete('/retirer-promotion', [PanierController::class, 'retirerPromotion']);
    Route::get('/nombre-articles', [PanierController::class, 'nombreArticles']);
});

// Recherche
Route::get('/recherche', [ProduitController::class, 'search']);
```

## 9. **Service Provider**

### `app/Providers/RepositoryServiceProvider.php`
```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interfaces\FamilleRepositoryInterface;
use App\Interfaces\CategorieRepositoryInterface;
use App\Interfaces\SousCategorieRepositoryInterface;
use App\Interfaces\ProduitRepositoryInterface;
use App\Interfaces\PromotionRepositoryInterface;
use App\Interfaces\AvisRepositoryInterface;
use App\Interfaces\PanierRepositoryInterface;
use App\Repositories\FamilleRepository;
use App\Repositories\CategorieRepository;
use App\Repositories\SousCategorieRepository;
use App\Repositories\ProduitRepository;
use App\Repositories\PromotionRepository;
use App\Repositories\AvisRepository;
use App\Repositories\PanierRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(FamilleRepositoryInterface::class, FamilleRepository::class);
        $this->app->bind(CategorieRepositoryInterface::class, CategorieRepository::class);
        $this->app->bind(SousCategorieRepositoryInterface::class, SousCategorieRepository::class);
        $this->app->bind(ProduitRepositoryInterface::class, ProduitRepository::class);
        $this->app->bind(PromotionRepositoryInterface::class, PromotionRepository::class);
        $this->app->bind(AvisRepositoryInterface::class, AvisRepository::class);
        $this->app->bind(PanierRepositoryInterface::class, PanierRepository::class);
    }
}
```

## 10. **Seeders pour les données de test**

### `database/seeders/EcommerceSeeder.php`
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Famille;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Produit;
use App\Models\User;

class EcommerceSeeder extends Seeder
{
    public function run()
    {
        // Créer des utilisateurs vendeurs
        $vendeurs = User::factory()->count(5)->create();

        // Créer des familles
        $familles = [
            ['nom' => 'Électronique', 'description' => 'Appareils électroniques'],
            ['nom' => 'Mode', 'description' => 'Vêtements et accessoires'],
            ['nom' => 'Maison', 'description' => 'Meubles et décoration'],
            ['nom' => 'Sport', 'description' => 'Articles de sport'],
            ['nom' => 'Beauté', 'description' => 'Produits de beauté'],
        ];

        foreach ($familles as $familleData) {
            Famille::create($familleData);
        }

        // Créer des catégories pour chaque famille
        $categories = [
            // Électronique
            ['famille_id' => 1, 'nom' => 'Téléphones', 'description' => 'Smartphones et téléphones'],
            ['famille_id' => 1, 'nom' => 'Ordinateurs', 'description' => 'PC portables et de bureau'],
            ['famille_id' => 1, 'nom' => 'TV & Audio', 'description' => 'Téléviseurs et systèmes audio'],
            
            // Mode
            ['famille_id' => 2, 'nom' => 'Homme', 'description' => 'Vêtements pour hommes'],
            ['famille_id' => 2, 'nom' => 'Femme', 'description' => 'Vêtements pour femmes'],
            ['famille_id' => 2, 'nom' => 'Enfant', 'description' => 'Vêtements pour enfants'],
            
            // Maison
            ['famille_id' => 3, 'nom' => 'Meubles', 'description' => 'Meubles de maison'],
            ['famille_id' => 3, 'nom' => 'Décoration', 'description' => 'Objets de décoration'],
            ['famille_id' => 3, 'nom' => 'Cuisine', 'description' => 'Ustensiles de cuisine'],
        ];

        foreach ($categories as $categorieData) {
            Categorie::create($categorieData);
        }

        // Créer des sous-catégories
        $sousCategories = [
            // Téléphones
            ['categorie_id' => 1, 'nom' => 'Smartphones', 'description' => 'Smartphones haut de gamme'],
            ['categorie_id' => 1, 'nom' => 'Téléphones basiques', 'description' => 'Téléphones simples'],
            
            // Ordinateurs
            ['categorie_id' => 2, 'nom' => 'PC Portables', 'description' => 'Ordinateurs portables'],
            ['categorie_id' => 2, 'nom' => 'PC Bureau', 'description' => 'Ordinateurs de bureau'],
            
            // Vêtements Homme
            ['categorie_id' => 4, 'nom' => 'Chemises', 'description' => 'Chemises pour hommes'],
            ['categorie_id' => 4, 'nom' => 'Pantalons', 'description' => 'Pantalons pour hommes'],
            
            // Vêtements Femme
            ['categorie_id' => 5, 'nom' => 'Robes', 'description' => 'Robes pour femmes'],
            ['categorie_id' => 5, 'nom' => 'Jupes', 'description' => 'Jupes pour femmes'],
        ];

        foreach ($sousCategories as $sousCategorieData) {
            SousCategorie::create($sousCategorieData);
        }

        // Créer des produits
        $produits = [
            [
                'nom' => 'iPhone 15 Pro',
                'reference' => 'IPH15PRO-256',
                'description' => 'Smartphone Apple iPhone 15 Pro 256GB',
                'prix' => 1299.99,
                'prix_promotion' => 1199.99,
                'quantite' => 50,
                'sous_categorie_id' => 1,
                'vendeur_id' => $vendeurs->random()->id,
                'marque' => 'Apple',
                'est_en_vedette' => true,
                'est_nouveau' => true,
            ],
            [
                'nom' => 'Samsung Galaxy S23',
                'reference' => 'SGS23-128',
                'description' => 'Smartphone Samsung Galaxy S23 128GB',
                'prix' => 899.99,
                'quantite' => 75,
                'sous_categorie_id' => 1,
                'vendeur_id' => $vendeurs->random()->id,
                'marque' => 'Samsung',
                'est_en_vedette' => true,
            ],
            [
                'nom' => 'MacBook Air M2',
                'reference' => 'MBAIR-M2-512',
                'description' => 'MacBook Air avec puce M2 512GB',
                'prix' => 1499.99,
                'prix_promotion' => 1399.99,
                'quantite' => 30,
                'sous_categorie_id' => 3,
                'vendeur_id' => $vendeurs->random()->id,
                'marque' => 'Apple',
                'est_nouveau' => true,
            ],
            [
                'nom' => 'Chemise Homme Slim Fit',
                'reference' => 'CHM-SLIM-BLUE',
                'description' => 'Chemise homme slim fit bleue',
                'prix' => 49.99,
                'quantite' => 200,
                'sous_categorie_id' => 5,
                'vendeur_id' => $vendeurs->random()->id,
                'marque' => 'Zara',
            ],
            [
                'nom' => 'Robe Élégante Soirée',
                'reference' => 'ROBE-SOIR-NOIR',
                'description' => 'Robe noire élégante pour soirée',
                'prix' => 89.99,
                'prix_promotion' => 69.99,
                'quantite' => 150,
                'sous_categorie_id' => 7,
                'vendeur_id' => $vendeurs->random()->id,
                'marque' => 'Mango',
                'est_en_vedette' => true,
            ],
        ];

        foreach ($produits as $produitData) {
            Produit::create($produitData);
        }
    }
}
```

## 11. **Configuration UUID dans les modèles**

### `app/Models/Traits/HasUuid.php` (Trait personnalisé)
```php
<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    protected static function bootHasUuid()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::uuid()->toString();
            }
        });
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }
}
```

## 12. **Middleware pour vérifier les UUID**

### `app/Http/Middleware/ValidateUuid.php`
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ValidateUuid
{
    public function handle(Request $request, Closure $next)
    {
        $routeParameters = $request->route()->parameters();
        
        foreach ($routeParameters as $parameter => $value) {
            if (Str::endsWith($parameter, 'id') || Str::endsWith($parameter, 'Id')) {
                if (!Str::isUuid($value)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'ID invalide',
                        'error' => 'L\'ID fourni n\'est pas un UUID valide'
                    ], 400);
                }
            }
        }

        return $next($request);
    }
}
```

## 13. **Enregistrement du middleware dans Kernel.php**
```php
// app/Http/Kernel.php
protected $routeMiddleware = [
    // ... autres middlewares
    'uuid' => \App\Http\Middleware\ValidateUuid::class,
];
```

## 14. **Utilisation dans les routes**
```php
Route::apiResource('produits', ProduitController::class)->middleware('uuid');
```

Ce code fournit une base complète pour un site e-commerce avec Laravel, incluant:

1. **Modèles complets** avec UUID et relations
2. **Repositories** pour chaque entité
3. **Controllers** avec toutes les opérations CRUD
4. **Resources** pour l'API
5. **Form Requests** pour la validation
6. **Migrations** complètes avec contraintes étrangères
7. **Routes API** organisées
8. **Service Provider** pour l'injection de dépendances
9. **Seeders** pour les données de test
10. **Middleware** pour valider les UUID

Le système supporte:
- Gestion multi-niveaux (Famille → Catégorie → Sous-catégorie → Produit)
- Panier avec gestion de session pour utilisateurs non connectés
- Promotions avec codes
- Système d'avis et ratings
- Gestion des stocks et alertes
- Images multiples par produit
- Recherche avancée avec filtres
- Produits en vedette, nouveaux, en promotion





Excellente observation ! J'ai oublié le système de commandes (orders) et les articles commandés. Voici l'ajout complet :

## 1. **Migrations supplémentaires**

### `database/migrations/xxxx_create_orders_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero_commande')->unique();
            $table->uuid('user_id');
            $table->enum('statut', [
                'en_attente', 
                'confirmee', 
                'en_preparation', 
                'expediee', 
                'livree', 
                'annulee', 
                'remboursee'
            ])->default('en_attente');
            
            $table->decimal('sous_total', 15, 2);
            $table->decimal('frais_livraison', 15, 2)->default(0);
            $table->decimal('taxes', 15, 2)->default(0);
            $table->decimal('reduction', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            
            $table->uuid('promotion_id')->nullable();
            $table->json('adresse_facturation');
            $table->json('adresse_livraison');
            $table->text('notes')->nullable();
            
            $table->string('methode_paiement')->nullable();
            $table->string('statut_paiement')->default('en_attente');
            $table->string('transaction_id')->nullable();
            
            $table->timestamp('date_commande')->useCurrent();
            $table->timestamp('date_confirmation')->nullable();
            $table->timestamp('date_expedition')->nullable();
            $table->timestamp('date_livraison')->nullable();
            $table->timestamp('date_annulation')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('promotion_id')
                  ->references('id')
                  ->on('promotions')
                  ->onDelete('set null');

            $table->index('numero_commande');
            $table->index('statut');
            $table->index('statut_paiement');
            $table->index('date_commande');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
};
```

### `database/migrations/xxxx_create_order_items_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('produit_id');
            $table->uuid('vendeur_id');
            
            $table->string('nom_produit');
            $table->string('reference_produit');
            $table->json('options')->nullable(); // Couleur, taille, etc.
            $table->integer('quantite');
            
            $table->decimal('prix_unitaire', 15, 2);
            $table->decimal('prix_total', 15, 2);
            
            $table->decimal('commission_vendeur', 5, 2)->nullable(); // Pourcentage de commission
            $table->decimal('montant_vendeur', 15, 2)->nullable(); // Montant que le vendeur reçoit
            
            $table->enum('statut', [
                'en_attente',
                'confirme',
                'en_preparation',
                'expedie',
                'livre',
                'retourne',
                'rembourse'
            ])->default('en_attente');
            
            $table->timestamp('date_expedition')->nullable();
            $table->timestamp('date_livraison')->nullable();
            $table->string('numero_suivi')->nullable();
            
            $table->timestamps();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');

            $table->foreign('produit_id')
                  ->references('id')
                  ->on('produits')
                  ->onDelete('cascade');

            $table->foreign('vendeur_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_items');
    }
};
```

### `database/migrations/xxxx_create_paiements_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('user_id');
            
            $table->string('reference')->unique();
            $table->decimal('montant', 15, 2);
            $table->string('devise')->default('XOF');
            
            $table->enum('methode', [
                'carte_credit',
                'mobile_money',
                'virement',
                'especes',
                'paypal',
                'stripe'
            ]);
            
            $table->enum('statut', [
                'en_attente',
                'en_cours',
                'reussi',
                'echoue',
                'annule',
                'rembourse'
            ])->default('en_attente');
            
            $table->json('details_paiement')->nullable();
            $table->json('reponse_paiement')->nullable();
            
            $table->timestamp('date_paiement')->nullable();
            $table->timestamp('date_confirmation')->nullable();
            $table->timestamp('date_remboursement')->nullable();
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index('reference');
            $table->index('statut');
            $table->index('date_paiement');
        });
    }

    public function down()
    {
        Schema::dropIfExists('paiements');
    }
};
```

### `database/migrations/xxxx_create_retours_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('retours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_item_id');
            $table->uuid('user_id');
            
            $table->string('numero_retour')->unique();
            $table->enum('raison', [
                'defaut_produit',
                'non_conforme',
                'erreur_commande',
                'change_avis',
                'trop_tard',
                'autre'
            ]);
            
            $table->text('description');
            $table->enum('statut', [
                'en_attente',
                'approuve',
                'refuse',
                'en_cours',
                'complete',
                'rembourse'
            ])->default('en_attente');
            
            $table->decimal('montant_remboursement', 15, 2)->nullable();
            $table->enum('type_remboursement', [
                'remboursement',
                'echange',
                'credit_boutique'
            ])->nullable();
            
            $table->json('preuves')->nullable(); // Photos du produit
            $table->text('notes_admin')->nullable();
            
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_approbation')->nullable();
            $table->timestamp('date_completion')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_item_id')
                  ->references('id')
                  ->on('order_items')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index('numero_retour');
            $table->index('statut');
            $table->index('date_demande');
        });
    }

    public function down()
    {
        Schema::dropIfExists('retours');
    }
};
```

## 2. **Modèles supplémentaires**

### `app/Models/Order.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'numero_commande',
        'user_id',
        'statut',
        'sous_total',
        'frais_livraison',
        'taxes',
        'reduction',
        'total',
        'promotion_id',
        'adresse_facturation',
        'adresse_livraison',
        'notes',
        'methode_paiement',
        'statut_paiement',
        'transaction_id',
        'date_commande',
        'date_confirmation',
        'date_expedition',
        'date_livraison',
        'date_annulation'
    ];

    protected $casts = [
        'sous_total' => 'decimal:2',
        'frais_livraison' => 'decimal:2',
        'taxes' => 'decimal:2',
        'reduction' => 'decimal:2',
        'total' => 'decimal:2',
        'adresse_facturation' => 'array',
        'adresse_livraison' => 'array',
        'date_commande' => 'datetime',
        'date_confirmation' => 'datetime',
        'date_expedition' => 'datetime',
        'date_livraison' => 'datetime',
        'date_annulation' => 'datetime',
    ];

    protected $appends = [
        'statut_label',
        'statut_paiement_label',
        'est_annulable',
        'est_retournable'
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function vendeurs()
    {
        return $this->hasManyThrough(User::class, OrderItem::class, 'order_id', 'id', 'id', 'vendeur_id');
    }

    // Accessors
    public function getStatutLabelAttribute()
    {
        $labels = [
            'en_attente' => 'En attente',
            'confirmee' => 'Confirmée',
            'en_preparation' => 'En préparation',
            'expediee' => 'Expédiée',
            'livree' => 'Livrée',
            'annulee' => 'Annulée',
            'remboursee' => 'Remboursée',
        ];

        return $labels[$this->statut] ?? $this->statut;
    }

    public function getStatutPaiementLabelAttribute()
    {
        $labels = [
            'en_attente' => 'En attente',
            'en_cours' => 'En cours',
            'reussi' => 'Réussi',
            'echoue' => 'Échoué',
            'annule' => 'Annulé',
            'rembourse' => 'Remboursé',
        ];

        return $labels[$this->statut_paiement] ?? $this->statut_paiement;
    }

    public function getEstAnnulableAttribute()
    {
        return in_array($this->statut, ['en_attente', 'confirmee']);
    }

    public function getEstRetournableAttribute()
    {
        if ($this->statut !== 'livree') {
            return false;
        }

        // Vérifier si la date de livraison est dans les 14 jours
        if ($this->date_livraison && $this->date_livraison->diffInDays(now()) <= 14) {
            return true;
        }

        return false;
    }

    // Méthodes
    public function confirmer()
    {
        $this->update([
            'statut' => 'confirmee',
            'date_confirmation' => now()
        ]);
    }

    public function expedier()
    {
        $this->update([
            'statut' => 'expediee',
            'date_expedition' => now()
        ]);
    }

    public function livrer()
    {
        $this->update([
            'statut' => 'livree',
            'date_livraison' => now()
        ]);
    }

    public function annuler()
    {
        $this->update([
            'statut' => 'annulee',
            'date_annulation' => now()
        ]);
    }

    public function markPaiementReussi()
    {
        $this->update([
            'statut_paiement' => 'reussi'
        ]);
    }

    public static function generateOrderNumber()
    {
        $prefix = 'CMD';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -6));
        
        return "{$prefix}-{$date}-{$random}";
    }

    public function calculerTotaux()
    {
        $sousTotal = $this->items->sum('prix_total');
        
        $reduction = 0;
        if ($this->promotion && $this->promotion->estValide()) {
            if ($this->promotion->type === 'pourcentage') {
                $reduction = $sousTotal * ($this->promotion->valeur / 100);
            } elseif ($this->promotion->type === 'fixe') {
                $reduction = $this->promotion->valeur;
            } elseif ($this->promotion->type === 'livraison_gratuite') {
                $reduction = $this->frais_livraison;
            }
        }

        $total = $sousTotal - $reduction + $this->frais_livraison + $this->taxes;

        $this->update([
            'sous_total' => $sousTotal,
            'reduction' => $reduction,
            'total' => max(0, $total)
        ]);
    }
}
```

### `app/Models/OrderItem.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'produit_id',
        'vendeur_id',
        'nom_produit',
        'reference_produit',
        'options',
        'quantite',
        'prix_unitaire',
        'prix_total',
        'commission_vendeur',
        'montant_vendeur',
        'statut',
        'date_expedition',
        'date_livraison',
        'numero_suivi'
    ];

    protected $casts = [
        'options' => 'array',
        'prix_unitaire' => 'decimal:2',
        'prix_total' => 'decimal:2',
        'montant_vendeur' => 'decimal:2',
        'commission_vendeur' => 'decimal:2',
        'date_expedition' => 'datetime',
        'date_livraison' => 'datetime',
    ];

    protected $appends = ['statut_label'];

    // Relations
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function vendeur()
    {
        return $this->belongsTo(User::class, 'vendeur_id');
    }

    public function retour()
    {
        return $this->hasOne(Retour::class);
    }

    // Accessors
    public function getStatutLabelAttribute()
    {
        $labels = [
            'en_attente' => 'En attente',
            'confirme' => 'Confirmé',
            'en_preparation' => 'En préparation',
            'expedie' => 'Expédié',
            'livre' => 'Livré',
            'retourne' => 'Retourné',
            'rembourse' => 'Remboursé',
        ];

        return $labels[$this->statut] ?? $this->statut;
    }

    // Méthodes
    public function expedier($numeroSuivi = null)
    {
        $this->update([
            'statut' => 'expedie',
            'date_expedition' => now(),
            'numero_suivi' => $numeroSuivi
        ]);
    }

    public function livrer()
    {
        $this->update([
            'statut' => 'livre',
            'date_livraison' => now()
        ]);
    }

    public function calculerCommission()
    {
        $commissionPourcentage = $this->commission_vendeur ?? 90; // Par défaut 90% pour le vendeur
        $montant = $this->prix_total * ($commissionPourcentage / 100);
        
        $this->update(['montant_vendeur' => $montant]);
        
        return $montant;
    }

    public function retourner($raison, $description, $preuves = [])
    {
        return Retour::create([
            'order_item_id' => $this->id,
            'user_id' => $this->order->user_id,
            'numero_retour' => Retour::generateReturnNumber(),
            'raison' => $raison,
            'description' => $description,
            'preuves' => $preuves,
            'montant_remboursement' => $this->prix_total
        ]);
    }
}
```

### `app/Models/Paiement.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Paiement extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'order_id',
        'user_id',
        'reference',
        'montant',
        'devise',
        'methode',
        'statut',
        'details_paiement',
        'reponse_paiement',
        'date_paiement',
        'date_confirmation',
        'date_remboursement',
        'notes'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'details_paiement' => 'array',
        'reponse_paiement' => 'array',
        'date_paiement' => 'datetime',
        'date_confirmation' => 'datetime',
        'date_remboursement' => 'datetime',
    ];

    protected $appends = ['statut_label', 'methode_label'];

    // Relations
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getStatutLabelAttribute()
    {
        $labels = [
            'en_attente' => 'En attente',
            'en_cours' => 'En cours',
            'reussi' => 'Réussi',
            'echoue' => 'Échoué',
            'annule' => 'Annulé',
            'rembourse' => 'Remboursé',
        ];

        return $labels[$this->statut] ?? $this->statut;
    }

    public function getMethodeLabelAttribute()
    {
        $labels = [
            'carte_credit' => 'Carte de crédit',
            'mobile_money' => 'Mobile Money',
            'virement' => 'Virement bancaire',
            'especes' => 'Espèces',
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
        ];

        return $labels[$this->methode] ?? $this->methode;
    }

    // Méthodes
    public function markReussi($details = [])
    {
        $this->update([
            'statut' => 'reussi',
            'date_confirmation' => now(),
            'reponse_paiement' => array_merge($this->reponse_paiement ?? [], $details)
        ]);

        $this->order->markPaiementReussi();
    }

    public function markEchoue($raison = null)
    {
        $this->update([
            'statut' => 'echoue',
            'notes' => $raison
        ]);
    }

    public function rembourser($montant = null)
    {
        $montantARembourser = $montant ?? $this->montant;
        
        $this->update([
            'statut' => 'rembourse',
            'date_remboursement' => now(),
            'montant' => $montantARembourser
        ]);
    }

    public static function generateReference()
    {
        $prefix = 'PAY';
        $date = now()->format('YmdHis');
        $random = strtoupper(substr(uniqid(), -6));
        
        return "{$prefix}{$date}{$random}";
    }
}
```

### `app/Models/Retour.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Retour extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'order_item_id',
        'user_id',
        'numero_retour',
        'raison',
        'description',
        'statut',
        'montant_remboursement',
        'type_remboursement',
        'preuves',
        'notes_admin',
        'date_demande',
        'date_approbation',
        'date_completion'
    ];

    protected $casts = [
        'preuves' => 'array',
        'montant_remboursement' => 'decimal:2',
        'date_demande' => 'datetime',
        'date_approbation' => 'datetime',
        'date_completion' => 'datetime',
    ];

    protected $appends = [
        'raison_label',
        'statut_label',
        'type_remboursement_label'
    ];

    // Relations
    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getRaisonLabelAttribute()
    {
        $labels = [
            'defaut_produit' => 'Défaut du produit',
            'non_conforme' => 'Non conforme à la description',
            'erreur_commande' => 'Erreur de commande',
            'change_avis' => 'Changement d\'avis',
            'trop_tard' => 'Livraison trop tardive',
            'autre' => 'Autre raison',
        ];

        return $labels[$this->raison] ?? $this->raison;
    }

    public function getStatutLabelAttribute()
    {
        $labels = [
            'en_attente' => 'En attente',
            'approuve' => 'Approuvé',
            'refuse' => 'Refusé',
            'en_cours' => 'En cours',
            'complete' => 'Complété',
            'rembourse' => 'Remboursé',
        ];

        return $labels[$this->statut] ?? $this->statut;
    }

    public function getTypeRemboursementLabelAttribute()
    {
        $labels = [
            'remboursement' => 'Remboursement',
            'echange' => 'Échange',
            'credit_boutique' => 'Crédit boutique',
        ];

        return $labels[$this->type_remboursement] ?? $this->type_remboursement;
    }

    // Méthodes
    public function approuver($typeRemboursement = 'remboursement')
    {
        $this->update([
            'statut' => 'approuve',
            'type_remboursement' => $typeRemboursement,
            'date_approbation' => now()
        ]);

        $this->orderItem->update(['statut' => 'retourne']);
    }

    public function refuser($raison)
    {
        $this->update([
            'statut' => 'refuse',
            'notes_admin' => $raison
        ]);
    }

    public function completer()
    {
        $this->update([
            'statut' => 'complete',
            'date_completion' => now()
        ]);
    }

    public static function generateReturnNumber()
    {
        $prefix = 'RET';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -6));
        
        return "{$prefix}-{$date}-{$random}";
    }
}
```

## 3. **Interfaces Repository supplémentaires**

### `app/Interfaces/OrderRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface OrderRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    
    public function getByUser($userId, $filters = []);
    public function getByVendeur($vendeurId, $filters = []);
    public function getByStatus($status, $filters = []);
    
    public function createFromPanier($panierId, array $adresseData, array $paiementData);
    public function updateStatus($id, $status);
    public function updateItemStatus($itemId, $status);
    
    public function getStatsByUser($userId);
    public function getStatsByVendeur($vendeurId);
    public function getRecentOrders($limit = 10);
    
    public function createPaiement($orderId, array $paiementData);
    public function createRetour($orderItemId, array $retourData);
    
    public function search($filters);
}
```

### `app/Interfaces/PaiementRepositoryInterface.php`
```php
<?php

namespace App\Interfaces;

interface PaiementRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function create(array $data);
    public function update($id, array $data);
    
    public function getByUser($userId);
    public function getByOrder($orderId);
    public function getByReference($reference);
    
    public function markReussi($id, $details = []);
    public function markEchoue($id, $raison = null);
    public function rembourser($id, $montant = null);
    
    public function verifierPaiement($reference);
}
```

## 4. **Repositories supplémentaires**

### `app/Repositories/OrderRepository.php`
```php
<?php

namespace App\Repositories;

use App\Interfaces\OrderRepositoryInterface;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Panier;
use App\Models\PanierItem;
use App\Models\Paiement;
use App\Models\Retour;
use Illuminate\Support\Facades\DB;

class OrderRepository implements OrderRepositoryInterface
{
    public function getAll()
    {
        return Order::with(['user', 'items.produit'])->orderBy('created_at', 'desc')->get();
    }

    public function getByID($id)
    {
        return Order::with([
            'user', 
            'items.produit.images', 
            'promotion', 
            'paiements',
            'items.retour'
        ])->find($id);
    }

    public function create(array $data)
    {
        return Order::create($data);
    }

    public function update($id, array $data)
    {
        $order = Order::find($id);
        if ($order) {
            $order->update($data);
            return $order;
        }
        return null;
    }

    public function delete($id)
    {
        $order = Order::find($id);
        if ($order) {
            return $order->delete();
        }
        return false;
    }

    public function getByUser($userId, $filters = [])
    {
        $query = Order::with(['items.produit.images'])
                     ->where('user_id', $userId);

        if (isset($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        if (isset($filters['date_debut'])) {
            $query->where('date_commande', '>=', $filters['date_debut']);
        }

        if (isset($filters['date_fin'])) {
            $query->where('date_commande', '<=', $filters['date_fin']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    public function getByVendeur($vendeurId, $filters = [])
    {
        $query = OrderItem::with(['order.user', 'produit.images'])
                         ->where('vendeur_id', $vendeurId)
                         ->whereHas('order', function ($q) use ($filters) {
                             if (isset($filters['statut'])) {
                                 $q->where('statut', $filters['statut']);
                             }
                         });

        if (isset($filters['date_debut'])) {
            $query->where('created_at', '>=', $filters['date_debut']);
        }

        if (isset($filters['date_fin'])) {
            $query->where('created_at', '<=', $filters['date_fin']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    public function getByStatus($status, $filters = [])
    {
        $query = Order::with(['user', 'items.produit'])
                     ->where('statut', $status);

        if (isset($filters['date_debut'])) {
            $query->where('date_commande', '>=', $filters['date_debut']);
        }

        if (isset($filters['date_fin'])) {
            $query->where('date_commande', '<=', $filters['date_fin']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    public function createFromPanier($panierId, array $adresseData, array $paiementData)
    {
        DB::beginTransaction();
        try {
            $panier = Panier::with(['items.produit', 'promotion'])->find($panierId);
            
            if (!$panier || $panier->items->isEmpty()) {
                throw new \Exception('Panier vide ou introuvable');
            }

            // Vérifier le stock pour chaque produit
            foreach ($panier->items as $item) {
                if ($item->produit->quantite < $item->quantite) {
                    throw new \Exception("Stock insuffisant pour: {$item->produit->nom}");
                }
            }

            // Créer la commande
            $order = Order::create([
                'numero_commande' => Order::generateOrderNumber(),
                'user_id' => $panier->user_id,
                'sous_total' => $panier->sous_total,
                'frais_livraison' => $panier->frais_livraison,
                'reduction' => $panier->reduction,
                'total' => $panier->total,
                'promotion_id' => $panier->promotion_id,
                'adresse_facturation' => $adresseData['facturation'],
                'adresse_livraison' => $adresseData['livraison'] ?? $adresseData['facturation'],
                'notes' => $adresseData['notes'] ?? null,
                'methode_paiement' => $paiementData['methode'] ?? 'carte_credit',
            ]);

            // Créer les articles de commande
            foreach ($panier->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'produit_id' => $item->produit_id,
                    'vendeur_id' => $item->produit->vendeur_id,
                    'nom_produit' => $item->produit->nom,
                    'reference_produit' => $item->produit->reference,
                    'options' => $item->options,
                    'quantite' => $item->quantite,
                    'prix_unitaire' => $item->prix_unitaire,
                    'prix_total' => $item->prix_total,
                ]);

                // Décrémenter le stock
                $item->produit->incrementVentes($item->quantite);
            }

            // Créer le paiement
            $paiement = Paiement::create([
                'order_id' => $order->id,
                'user_id' => $panier->user_id,
                'reference' => Paiement::generateReference(),
                'montant' => $order->total,
                'methode' => $paiementData['methode'] ?? 'carte_credit',
                'details_paiement' => $paiementData['details'] ?? [],
            ]);

            // Vider le panier
            $panier->viderPanier();

            DB::commit();
            
            return [
                'order' => $order,
                'paiement' => $paiement
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateStatus($id, $status)
    {
        $order = Order::find($id);
        if (!$order) {
            return null;
        }

        $order->update(['statut' => $status]);
        
        // Mettre à jour les dates selon le statut
        switch ($status) {
            case 'confirmee':
                $order->confirmer();
                break;
            case 'expediee':
                $order->expedier();
                break;
            case 'livree':
                $order->livrer();
                break;
            case 'annulee':
                $order->annuler();
                break;
        }

        return $order;
    }

    public function updateItemStatus($itemId, $status)
    {
        $item = OrderItem::find($itemId);
        if (!$item) {
            return null;
        }

        $item->update(['statut' => $status]);
        
        if ($status === 'expedie') {
            $item->expedier();
        } elseif ($status === 'livre') {
            $item->livrer();
        }

        return $item;
    }

    public function getStatsByUser($userId)
    {
        $stats = Order::where('user_id', $userId)
                     ->selectRaw('
                         COUNT(*) as total_commandes,
                         SUM(CASE WHEN statut = "livree" THEN 1 ELSE 0 END) as commandes_livrees,
                         SUM(CASE WHEN statut = "en_attente" THEN 1 ELSE 0 END) as commandes_en_attente,
                         SUM(CASE WHEN statut = "annulee" THEN 1 ELSE 0 END) as commandes_annulees,
                         SUM(total) as montant_total
                     ')
                     ->first();

        $stats->depense_moyenne = $stats->total_commandes > 0 
            ? $stats->montant_total / $stats->total_commandes 
            : 0;

        return $stats;
    }

    public function getStatsByVendeur($vendeurId)
    {
        $stats = OrderItem::where('vendeur_id', $vendeurId)
                         ->selectRaw('
                             COUNT(*) as total_items,
                             SUM(quantite) as total_quantite,
                             SUM(prix_total) as chiffre_affaires,
                             AVG(commission_vendeur) as commission_moyenne
                         ')
                         ->first();

        $stats->montant_vendeur = $stats->chiffre_affaires * ($stats->commission_moyenne / 100);

        return $stats;
    }

    public function getRecentOrders($limit = 10)
    {
        return Order::with(['user', 'items'])
                   ->orderBy('created_at', 'desc')
                   ->limit($limit)
                   ->get();
    }

    public function createPaiement($orderId, array $paiementData)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return null;
        }

        $paiement = Paiement::create([
            'order_id' => $orderId,
            'user_id' => $order->user_id,
            'reference' => Paiement::generateReference(),
            'montant' => $paiementData['montant'] ?? $order->total,
            'methode' => $paiementData['methode'] ?? 'carte_credit',
            'details_paiement' => $paiementData['details'] ?? [],
            'statut' => 'en_attente'
        ]);

        return $paiement;
    }

    public function createRetour($orderItemId, array $retourData)
    {
        $item = OrderItem::with('order')->find($orderItemId);
        if (!$item) {
            return null;
        }

        $retour = Retour::create([
            'order_item_id' => $orderItemId,
            'user_id' => $item->order->user_id,
            'numero_retour' => Retour::generateReturnNumber(),
            'raison' => $retourData['raison'],
            'description' => $retourData['description'],
            'preuves' => $retourData['preuves'] ?? [],
            'montant_remboursement' => $item->prix_total,
            'statut' => 'en_attente'
        ]);

        return $retour;
    }

    public function search($filters)
    {
        $query = Order::with(['user', 'items']);

        if (isset($filters['numero_commande'])) {
            $query->where('numero_commande', 'LIKE', "%{$filters['numero_commande']}%");
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        if (isset($filters['date_debut'])) {
            $query->where('date_commande', '>=', $filters['date_debut']);
        }

        if (isset($filters['date_fin'])) {
            $query->where('date_commande', '<=', $filters['date_fin']);
        }

        if (isset($filters['montant_min'])) {
            $query->where('total', '>=', $filters['montant_min']);
        }

        if (isset($filters['montant_max'])) {
            $query->where('total', '<=', $filters['montant_max']);
        }

        // Tri
        $sortBy = $filters['sort_by'] ?? 'date_commande';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $filters['per_page'] ?? 15;
        
        return $query->paginate($perPage);
    }
}
```

## 5. **Controllers supplémentaires**

### `app/Http/Controllers/API/OrderController.php`
```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\OrderRepositoryInterface;
use App\Interfaces\PaiementRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Requests\Order\CreateRetourRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\PaiementResource;
use App\Http\Resources\RetourResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    private OrderRepositoryInterface $orderRepository;
    private PaiementRepositoryInterface $paiementRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        PaiementRepositoryInterface $paiementRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->paiementRepository = $paiementRepository;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->all();
            $orders = $this->orderRepository->search($filters);
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Commandes récupérées avec succès',
                [
                    'current_page' => $orders->currentPage(),
                    'total_pages' => $orders->lastPage(),
                    'total_items' => $orders->total(),
                    'per_page' => $orders->perPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des commandes');
        }
    }

    public function show($id)
    {
        try {
            $order = $this->orderRepository->getByID($id);
            if (!$order) {
                return ApiResponseClass::notFound('Commande introuvable');
            }

            return ApiResponseClass::sendResponse(
                new OrderDetailResource($order),
                'Commande récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération de la commande');
        }
    }

    public function store(CreateOrderRequest $request)
    {
        DB::beginTransaction();
        try {
            $panierId = $request->input('panier_id');
            $adresseData = $request->only(['facturation', 'livraison', 'notes']);
            $paiementData = $request->only(['methode', 'details']);
            
            $result = $this->orderRepository->createFromPanier($panierId, $adresseData, $paiementData);

            DB::commit();
            return ApiResponseClass::created(
                [
                    'order' => new OrderDetailResource($result['order']),
                    'paiement' => new PaiementResource($result['paiement'])
                ],
                'Commande créée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de la commande: " . $e->getMessage());
        }
    }

    public function update($id, UpdateOrderRequest $request)
    {
        DB::beginTransaction();
        try {
            $order = $this->orderRepository->update($id, $request->validated());

            if (!$order) {
                DB::rollBack();
                return ApiResponseClass::notFound('Commande introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new OrderDetailResource($order),
                'Commande mise à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour de la commande");
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->orderRepository->delete($id);

            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Commande introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Commande supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de la commande");
        }
    }

    public function byUser(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $filters = $request->all();
            
            $orders = $this->orderRepository->getByUser($userId, $filters);
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Commandes de l\'utilisateur récupérées avec succès',
                [
                    'current_page' => $orders->currentPage(),
                    'total_pages' => $orders->lastPage(),
                    'total_items' => $orders->total(),
                    'per_page' => $orders->perPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des commandes de l\'utilisateur');
        }
    }

    public function byVendeur(Request $request)
    {
        try {
            $vendeurId = $request->user()->id;
            $filters = $request->all();
            
            $items = $this->orderRepository->getByVendeur($vendeurId, $filters);
            
            return ApiResponseClass::sendResponse(
                $items,
                'Commandes du vendeur récupérées avec succès',
                [
                    'current_page' => $items->currentPage(),
                    'total_pages' => $items->lastPage(),
                    'total_items' => $items->total(),
                    'per_page' => $items->perPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des commandes du vendeur');
        }
    }

    public function byStatus($status, Request $request)
    {
        try {
            $filters = $request->all();
            $orders = $this->orderRepository->getByStatus($status, $filters);
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Commandes par statut récupérées avec succès',
                [
                    'current_page' => $orders->currentPage(),
                    'total_pages' => $orders->lastPage(),
                    'total_items' => $orders->total(),
                    'per_page' => $orders->perPage(),
                ]
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des commandes par statut');
        }
    }

    public function updateStatus($id, Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'statut' => 'required|in:en_attente,confirmee,en_preparation,expediee,livree,annulee,remboursee',
            ]);

            $order = $this->orderRepository->updateStatus($id, $request->statut);

            if (!$order) {
                DB::rollBack();
                return ApiResponseClass::notFound('Commande introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new OrderResource($order),
                'Statut de la commande mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du statut de la commande");
        }
    }

    public function updateItemStatus($itemId, Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'statut' => 'required|in:en_attente,confirme,en_preparation,expedie,livre,retourne,rembourse',
                'numero_suivi' => 'nullable|string',
            ]);

            $item = $this->orderRepository->updateItemStatus($itemId, $request->statut);

            if (!$item) {
                DB::rollBack();
                return ApiResponseClass::notFound('Article introuvable');
            }

            if ($request->has('numero_suivi') && $request->statut === 'expedie') {
                $item->update(['numero_suivi' => $request->numero_suivi]);
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                $item,
                'Statut de l\'article mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du statut de l'article");
        }
    }

    public function statsUser(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $stats = $this->orderRepository->getStatsByUser($userId);
            
            return ApiResponseClass::sendResponse(
                $stats,
                'Statistiques de commandes récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des statistiques');
        }
    }

    public function statsVendeur(Request $request)
    {
        try {
            $vendeurId = $request->user()->id;
            $stats = $this->orderRepository->getStatsByVendeur($vendeurId);
            
            return ApiResponseClass::sendResponse(
                $stats,
                'Statistiques du vendeur récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des statistiques du vendeur');
        }
    }

    public function recent()
    {
        try {
            $orders = $this->orderRepository->getRecentOrders();
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Commandes récentes récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des commandes récentes');
        }
    }

    public function createRetour($orderItemId, CreateRetourRequest $request)
    {
        DB::beginTransaction();
        try {
            $retour = $this->orderRepository->createRetour($orderItemId, $request->validated());

            DB::commit();
            return ApiResponseClass::created(
                new RetourResource($retour),
                'Demande de retour créée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de la demande de retour");
        }
    }

    public function annuler($id, Request $request)
    {
        DB::beginTransaction();
        try {
            $order = Order::find($id);
            if (!$order) {
                return ApiResponseClass::notFound('Commande introuvable');
            }

            if (!$order->est_annulable) {
                return ApiResponseClass::error('Cette commande ne peut pas être annulée', 400);
            }

            $order->annuler();

            // Rembourser le paiement si déjà effectué
            if ($order->paiements->isNotEmpty()) {
                foreach ($order->paiements as $paiement) {
                    $this->paiementRepository->rembourser($paiement->id);
                }
            }

            DB::commit();
            return ApiResponseClass::sendResponse(
                new OrderResource($order),
                'Commande annulée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de l'annulation de la commande");
        }
    }
}
```

## 6. **Resources supplémentaires**

### `app/Http/Resources/OrderResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'numero_commande' => $this->numero_commande,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label,
            'statut_paiement' => $this->statut_paiement,
            'statut_paiement_label' => $this->statut_paiement_label,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'taxes' => $this->taxes,
            'reduction' => $this->reduction,
            'total' => $this->total,
            'date_commande' => $this->date_commande,
            'est_annulable' => $this->est_annulable,
            'est_retournable' => $this->est_retournable,
            'user' => new UserResource($this->whenLoaded('user')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/OrderDetailResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'numero_commande' => $this->numero_commande,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label,
            'statut_paiement' => $this->statut_paiement,
            'statut_paiement_label' => $this->statut_paiement_label,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'taxes' => $this->taxes,
            'reduction' => $this->reduction,
            'total' => $this->total,
            'adresse_facturation' => $this->adresse_facturation,
            'adresse_livraison' => $this->adresse_livraison,
            'notes' => $this->notes,
            'methode_paiement' => $this->methode_paiement,
            'transaction_id' => $this->transaction_id,
            'date_commande' => $this->date_commande,
            'date_confirmation' => $this->date_confirmation,
            'date_expedition' => $this->date_expedition,
            'date_livraison' => $this->date_livraison,
            'date_annulation' => $this->date_annulation,
            'est_annulable' => $this->est_annulable,
            'est_retournable' => $this->est_retournable,
            'user' => new UserResource($this->whenLoaded('user')),
            'promotion' => new PromotionResource($this->whenLoaded('promotion')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'paiements' => PaiementResource::collection($this->whenLoaded('paiements')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/OrderItemResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'nom_produit' => $this->nom_produit,
            'reference_produit' => $this->reference_produit,
            'options' => $this->options,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'prix_total' => $this->prix_total,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label,
            'numero_suivi' => $this->numero_suivi,
            'date_expedition' => $this->date_expedition,
            'date_livraison' => $this->date_livraison,
            'produit' => new ProduitResource($this->whenLoaded('produit')),
            'vendeur' => new UserResource($this->whenLoaded('vendeur')),
            'retour' => new RetourResource($this->whenLoaded('retour')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/PaiementResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaiementResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'montant' => $this->montant,
            'devise' => $this->devise,
            'methode' => $this->methode,
            'methode_label' => $this->methode_label,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label,
            'date_paiement' => $this->date_paiement,
            'date_confirmation' => $this->date_confirmation,
            'date_remboursement' => $this->date_remboursement,
            'order' => new OrderResource($this->whenLoaded('order')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### `app/Http/Resources/RetourResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RetourResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'numero_retour' => $this->numero_retour,
            'raison' => $this->raison,
            'raison_label' => $this->raison_label,
            'description' => $this->description,
            'statut' => $this->statut,
            'statut_label' => $this->statut_label,
            'montant_remboursement' => $this->montant_remboursement,
            'type_remboursement' => $this->type_remboursement,
            'type_remboursement_label' => $this->type_remboursement_label,
            'preuves' => $this->preuves,
            'notes_admin' => $this->notes_admin,
            'date_demande' => $this->date_demande,
            'date_approbation' => $this->date_approbation,
            'date_completion' => $this->date_completion,
            'order_item' => new OrderItemResource($this->whenLoaded('orderItem')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## 7. **Form Requests supplémentaires**

### `app/Http/Requests/Order/CreateOrderRequest.php`
```php
<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'panier_id' => 'required|uuid|exists:paniers,id',
            
            'facturation.nom' => 'required|string|max:255',
            'facturation.prenom' => 'required|string|max:255',
            'facturation.email' => 'required|email',
            'facturation.telephone' => 'required|string|max:20',
            'facturation.adresse' => 'required|string',
            'facturation.code_postal' => 'required|string|max:10',
            'facturation.ville' => 'required|string|max:100',
            'facturation.pays' => 'required|string|max:100',
            
            'livraison.nom' => 'nullable|string|max:255',
            'livraison.prenom' => 'nullable|string|max:255',
            'livraison.email' => 'nullable|email',
            'livraison.telephone' => 'nullable|string|max:20',
            'livraison.adresse' => 'nullable|string',
            'livraison.code_postal' => 'nullable|string|max:10',
            'livraison.ville' => 'nullable|string|max:100',
            'livraison.pays' => 'nullable|string|max:100',
            
            'notes' => 'nullable|string|max:500',
            
            'methode' => 'required|in:carte_credit,mobile_money,virement,especes,paypal,stripe',
            'details' => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'panier_id.required' => 'Le panier est requis',
            'facturation.nom.required' => 'Le nom est requis',
            'facturation.prenom.required' => 'Le prénom est requis',
            'facturation.email.required' => 'L\'email est requis',
            'facturation.telephone.required' => 'Le téléphone est requis',
            'facturation.adresse.required' => 'L\'adresse est requise',
            'facturation.code_postal.required' => 'Le code postal est requis',
            'facturation.ville.required' => 'La ville est requise',
            'facturation.pays.required' => 'Le pays est requis',
            'methode.required' => 'La méthode de paiement est requise',
        ];
    }
}
```

## 8. **Routes API supplémentaires**

### Ajouter dans `routes/api.php`
```php
// Commandes
Route::apiResource('orders', OrderController::class);
Route::get('orders/par-statut/{statut}', [OrderController::class, 'byStatus']);
Route::get('orders/mes-commandes', [OrderController::class, 'byUser']);
Route::get('orders/vendeur/mes-commandes', [OrderController::class, 'byVendeur']);
Route::put('orders/{id}/statut', [OrderController::class, 'updateStatus']);
Route::put('orders/items/{itemId}/statut', [OrderController::class, 'updateItemStatus']);
Route::get('orders/recentes', [OrderController::class, 'recent']);
Route::get('orders/stats/user', [OrderController::class, 'statsUser']);
Route::get('orders/stats/vendeur', [OrderController::class, 'statsVendeur']);
Route::post('orders/items/{itemId}/retour', [OrderController::class, 'createRetour']);
Route::post('orders/{id}/annuler', [OrderController::class, 'annuler']);

// Paiements
Route::apiResource('paiements', PaiementController::class);
Route::get('paiements/reference/{reference}', [PaiementController::class, 'byReference']);
Route::post('paiements/{id}/reussi', [PaiementController::class, 'markReussi']);
Route::post('paiements/{id}/echoue', [PaiementController::class, 'markEchoue']);
Route::post('paiements/{id}/rembourser', [PaiementController::class, 'rembourser']);

// Retours
Route::apiResource('retours', RetourController::class);
Route::put('retours/{id}/approuver', [RetourController::class, 'approuver']);
Route::put('retours/{id}/refuser', [RetourController::class, 'refuser']);
Route::put('retours/{id}/completer', [RetourController::class, 'completer']);
```

## 9. **Ajouter au Service Provider**

### `app/Providers/RepositoryServiceProvider.php`
```php
public function register()
{
    // ... autres bindings existants
    
    $this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);
    $this->app->bind(PaiementRepositoryInterface::class, PaiementRepository::class);
}
```

## 10. **Seeder mis à jour**

### Ajouter dans `database/seeders/EcommerceSeeder.php`
```php
public function run()
{
    // ... code existant pour familles, catégories, produits
    
    // Créer des commandes d'exemple
    $this->createSampleOrders();
}

private function createSampleOrders()
{
    $users = User::where('role', 'user')->limit(5)->get();
    $produits = Produit::all();
    
    foreach ($users as $user) {
        // Créer un panier pour l'utilisateur
        $panier = Panier::create([
            'user_id' => $user->id,
            'sous_total' => 0,
            'frais_livraison' => 5.99,
            'total' => 0,
        ]);
        
        // Ajouter 2-4 produits au panier
        $produitsSelectionnes = $produits->random(rand(2, 4));
        $sousTotal = 0;
        
        foreach ($produitsSelectionnes as $produit) {
            $quantite = rand(1, 3);
            $prixTotal = $quantite * $produit->prix_final;
            $sousTotal += $prixTotal;
            
            PanierItem::create([
                'panier_id' => $panier->id,
                'produit_id' => $produit->id,
                'quantite' => $quantite,
                'prix_unitaire' => $produit->prix_final,
                'prix_total' => $prixTotal,
            ]);
        }
        
        $panier->update([
            'sous_total' => $sousTotal,
            'total' => $sousTotal + $panier->frais_livraison,
        ]);
        
        // Créer une commande à partir du panier
        $order = Order::create([
            'numero_commande' => Order::generateOrderNumber(),
            'user_id' => $user->id,
            'statut' => ['en_attente', 'confirmee', 'expediee', 'livree'][rand(0, 3)],
            'sous_total' => $panier->sous_total,
            'frais_livraison' => $panier->frais_livraison,
            'total' => $panier->total,
            'adresse_facturation' => [
                'nom' => $user->name,
                'email' => $user->email,
                'telephone' => '+221 77 123 45 67',
                'adresse' => '123 Rue Example',
                'ville' => 'Dakar',
                'pays' => 'Sénégal',
                'code_postal' => '12500',
            ],
            'adresse_livraison' => [
                'nom' => $user->name,
                'email' => $user->email,
                'telephone' => '+221 77 123 45 67',
                'adresse' => '456 Avenue de la Liberté',
                'ville' => 'Dakar',
                'pays' => 'Sénégal',
                'code_postal' => '12500',
            ],
            'date_commande' => now()->subDays(rand(0, 30)),
        ]);
        
        // Créer les articles de commande
        foreach ($panier->items as $item) {
            $produit = $item->produit;
            
            OrderItem::create([
                'order_id' => $order->id,
                'produit_id' => $produit->id,
                'vendeur_id' => $produit->vendeur_id,
                'nom_produit' => $produit->nom,
                'reference_produit' => $produit->reference,
                'quantite' => $item->quantite,
                'prix_unitaire' => $item->prix_unitaire,
                'prix_total' => $item->prix_total,
                'statut' => $order->statut === 'livree' ? 'livre' : 'en_attente',
                'commission_vendeur' => 90,
            ]);
        }
        
        // Créer un paiement
        if ($order->statut !== 'en_attente') {
            Paiement::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'reference' => Paiement::generateReference(),
                'montant' => $order->total,
                'methode' => ['carte_credit', 'mobile_money', 'virement'][rand(0, 2)],
                'statut' => $order->statut === 'en_attente' ? 'en_attente' : 'reussi',
                'date_paiement' => $order->date_commande,
                'date_confirmation' => $order->statut !== 'en_attente' ? $order->date_commande->addMinutes(5) : null,
            ]);
        }
        
        // Vider le panier après création de la commande
        $panier->viderPanier();
    }
}
```

Maintenant vous avez un système complet de commandes avec :

1. **Commandes** avec numéro unique et suivi de statut
2. **Articles commandés** avec détails des produits, quantités, prix
3. **Paiements** avec différentes méthodes et suivi
4. **Retours** avec gestion des remboursements et échanges
5. **Adresses** de facturation et livraison
6. **Suivi** des dates importantes
7. **Statistiques** pour utilisateurs et vendeurs
8. **Gestion multi-vendeurs** avec commissions

Le système est maintenant complet avec :
- ✅ Produits, catégories, sous-catégories, familles
- ✅ Panier
- ✅ Promotions
- ✅ Avis et ratings
- ✅ Commandes et articles commandés
- ✅ Paiements
- ✅ Retours
- ✅ Gestion des stocks
- ✅ Recherche et filtres
- ✅ API REST complète avec UUID

Tout est prêt pour un site e-commerce de type AliExpress !