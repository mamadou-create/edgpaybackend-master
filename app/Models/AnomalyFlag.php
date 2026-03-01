<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signal d'anomalie détecté par le moteur anti-fraude.
 */
class AnomalyFlag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'reference_id',
        'reference_type',
        'type_anomalie',
        'niveau',
        'description',
        'donnees_contexte',
        'resolved',
        'resolu_par',
        'resolu_at',
        'note_resolution',
    ];

    protected function casts(): array
    {
        return [
            'donnees_contexte' => 'array',
            'resolved'         => 'boolean',
            'resolu_at'        => 'datetime',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolveur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolu_par');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeCritiques($query)
    {
        return $query->where('niveau', 'critique')->where('resolved', false);
    }

    public function scopeNonResolus($query)
    {
        return $query->where('resolved', false);
    }
}
