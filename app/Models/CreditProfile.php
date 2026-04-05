<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil de crédit d'un client PRO.
 *
 * @property string $id
 * @property string $user_id
 * @property float  $credit_limite
 * @property float  $credit_disponible
 * @property int    $score_fiabilite        0-100
 * @property string $niveau_risque          faible|moyen|eleve
 * @property bool   $est_bloque
 */
class CreditProfile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'credit_limite',
        'credit_disponible',
        'score_fiabilite',
        'niveau_risque',
        'est_bloque',
        'bloque_jusqu_au',
        'motif_blocage',
        'total_encours',
        'nb_creances_total',
        'nb_paiements_en_retard',
        'nb_paiements_rapides',
        'delai_moyen_paiement_jours',
        'anciennete_mois',
        'montant_moyen_transaction',
        'volume_mensuel_moyen',
        'score_calcule_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_limite'             => 'decimal:2',
            'credit_disponible'         => 'decimal:2',
            'total_encours'             => 'decimal:2',
            'montant_moyen_transaction' => 'decimal:2',
            'volume_mensuel_moyen'      => 'decimal:2',
            'delai_moyen_paiement_jours'=> 'decimal:2',
            'score_fiabilite'           => 'integer',
            'est_bloque'                => 'boolean',
            'bloque_jusqu_au'           => 'datetime',
            'score_calcule_at'          => 'datetime',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scoreHistories(): HasMany
    {
        return $this->hasMany(CreditScoreHistory::class, 'user_id', 'user_id');
    }

    // ─── Accesseurs utilitaires ───────────────────────────────────────────────

    /** Ratio endettement : encours / limite */
    public function getRatioEndettementAttribute(): float
    {
        if ($this->credit_limite <= 0) {
            return 0;
        }
        return round(($this->total_encours / $this->credit_limite) * 100, 2);
    }

    /** Le compte est-il effectivement bloqué en ce moment ? */
    public function estActuellementBloque(): bool
    {
        if (! $this->est_bloque) {
            return false;
        }
        if ($this->bloque_jusqu_au && $this->bloque_jusqu_au->isPast()) {
            return false; // déblocage automatique par expiration
        }
        return true;
    }
}
