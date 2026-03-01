<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail immuable — enregistre chaque action sensible dans le système.
 *
 * Règles :
 *   - Jamais de UPDATE ou DELETE sur cette table.
 *   - Accessible uniquement en lecture depuis l'application.
 */
class AuditLog extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'cible_id',
        'cible_type',
        'action',
        'module',
        'resultat',
        'donnees_avant',
        'donnees_apres',
        'contexte',
        'ip_address',
        'user_agent',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'donnees_avant' => 'array',
            'donnees_apres' => 'array',
            'contexte'      => 'array',
            'created_at'    => 'datetime',
        ];
    }

    // ─── Immutabilité ─────────────────────────────────────────────────────────

    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('[AuditLog] Un log d\'audit ne peut jamais être modifié.');
        });

        static::deleting(function () {
            throw new \RuntimeException('[AuditLog] Un log d\'audit ne peut jamais être supprimé.');
        });
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
