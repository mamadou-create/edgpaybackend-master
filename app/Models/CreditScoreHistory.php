<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique de l'évolution du score de crédit d'un client.
 */
class CreditScoreHistory extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'score_avant',
        'score_apres',
        'niveau_risque_apres',
        'credit_limite_apres',
        'declencheur',
        'declencheur_id',
        'variables_scoring',
    ];

    protected function casts(): array
    {
        return [
            'score_avant'        => 'integer',
            'score_apres'        => 'integer',
            'credit_limite_apres'=> 'decimal:2',
            'variables_scoring'  => 'array',
            'created_at'         => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDeltaScoreAttribute(): int
    {
        return $this->score_apres - $this->score_avant;
    }
}
