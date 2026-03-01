<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger comptable IMMUABLE.
 *
 * Règles absolues :
 *   - Jamais de UPDATE sur cette table.
 *   - Jamais de DELETE sur cette table.
 *   - Toute correction passe par une écriture inverse (débit/crédit compensatoire).
 *   - hash_integrite vérifié à chaque lecture critique.
 */
class LedgerEntry extends Model
{
    use HasUuids;

    // Pas de updated_at — immuabilité
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'montant',
        'balance_avant',
        'balance_apres',
        'reference_type',
        'reference_id',
        'description',
        'hash_integrite',
        'precedent_hash',
        'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'montant'      => 'decimal:2',
            'balance_avant'=> 'decimal:2',
            'balance_apres'=> 'decimal:2',
            'created_at'   => 'datetime',
        ];
    }

    // ─── Immutabilité ─────────────────────────────────────────────────────────

    /**
     * Bloque toute mise à jour — un ledger entry ne peut jamais être modifié.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException(
                '[LedgerEntry] Violation d\'immuabilité : une entrée de ledger ne peut jamais être modifiée.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                '[LedgerEntry] Violation d\'immuabilité : une entrée de ledger ne peut jamais être supprimée.'
            );
        });
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    // ─── Vérification intégrité ───────────────────────────────────────────────

    /**
     * Vérifie que le hash de cette entrée est cohérent avec les données stockées.
     */
    public function verifierIntegrite(string $secretKey): bool
    {
        $payload = implode('|', [
            $this->user_id,
            $this->montant,
            $this->created_at->timestamp,
            $secretKey,
        ]);
        return hash('sha256', $payload) === $this->hash_integrite;
    }
}
