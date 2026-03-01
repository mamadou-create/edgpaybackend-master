<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaction de remboursement sur une créance.
 * Paiement total, partiel, pénalité ou remise.
 */
class CreanceTransaction extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'creance_id',
        'user_id',
        'validateur_id',
        'montant',
        'montant_avant',
        'montant_apres',
        'type',
        'statut',
        'preuve_fichier',
        'preuve_mimetype',
        'preuve_hash',
        'receipt_number',
        'receipt_issued_at',
        'idempotency_key',
        'batch_key',
        'notes',
        'motif_rejet',
        'ip_soumission',
        'valide_at',
    ];

    protected function casts(): array
    {
        return [
            'montant'      => 'decimal:2',
            'montant_avant'=> 'decimal:2',
            'montant_apres'=> 'decimal:2',
            'valide_at'    => 'datetime',
            'receipt_issued_at' => 'datetime',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function creance(): BelongsTo
    {
        return $this->belongsTo(Creance::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validateur_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeValides($query)
    {
        return $query->where('statut', 'valide');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }
}
