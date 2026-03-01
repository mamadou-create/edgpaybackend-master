<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Créance PRO — dette client générée par une commande à crédit.
 *
 * Cycle de vie : en_attente → en_cours → partiellement_payee → payee
 *                                                              → en_retard → contentieux
 */
class Creance extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'adminstrateur_id',
        'commande_id',
        'reference',
        'montant_total',
        'montant_paye',
        'montant_restant',
        'statut',
        'date_echeance',
        'date_paiement_effectif',
        'jours_retard',
        'description',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'montant_total'           => 'decimal:2',
            'montant_paye'            => 'decimal:2',
            'montant_restant'         => 'decimal:2',
            'date_echeance'           => 'date',
            'date_paiement_effectif'  => 'date',
            'metadata'                => 'array',
            'jours_retard'            => 'integer',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function administrateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adminstrateur_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreanceTransaction::class, 'creance_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(AnomalyFlag::class, 'reference_id')
                    ->where('reference_type', self::class);
    }

    // ─── Accesseurs ───────────────────────────────────────────────────────────

    public function getEstEnRetardAttribute(): bool
    {
        return $this->date_echeance
            && $this->date_echeance->isPast()
            && ! in_array($this->statut, ['payee', 'annulee']);
    }

    public function getProgressPaiementAttribute(): float
    {
        if ($this->montant_total <= 0) {
            return 0;
        }
        return round(($this->montant_paye / $this->montant_total) * 100, 2);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeEnRetard($query)
    {
        return $query->where('statut', 'en_retard')
                     ->orWhere(function ($q) {
                         $q->where('date_echeance', '<', now())
                           ->whereNotIn('statut', ['payee', 'annulee']);
                     });
    }

    public function scopeEnCours($query)
    {
        return $query->whereIn('statut', ['en_attente', 'en_cours', 'partiellement_payee', 'en_retard']);
    }
}
