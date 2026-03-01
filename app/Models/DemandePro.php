<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemandePro extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'user_id',
        'nom',
        'prenom',
        'entreprise',
        'ville',
        'quartier',
        'type_piece',
        'numero_piece',
        'piece_image_path',
        'email',
        'adresse',
        'telephone',
        'status',
        'date_demande',
        'date_decision',
        'cancellation_reason',
        'cancelled_at',
    ];


    
    protected function casts(): array
    {
        return [
            'date_demande' => 'datetime',
            'date_decision' => 'datetime',
            'cancelled_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // Relation avec l'utilisateur
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEnAttente($query)
    {
        return $query->where('status', 'en attente');
    }

    public function getStatusBadgeAttribute()
    {
        return match ($this->status) {
            'accepté' => '🟢 Accepté',
            'refusé' => '🔴 Refusé',
            'annulé' => '🟡 Annulé',
            default => '🕓 En attente',
        };
    }

    /**
     * Scope pour les demandes annulées
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'annulé');
    }

    /**
     * Scope pour les demandes actives (non annulées et non supprimées)
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'annulé');
    }

    /**
     * Vérifier si la demande est annulée
     */
    public function isCancelled(): bool
    {
        return $this->status === 'annulé';
    }

    /**
     * Vérifier si la demande peut être annulée
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['en_attente']);
    }

    /**
     * Vérifier si la demande a été soft delete
     */
    public function isTrashed(): bool
    {
        return !is_null($this->deleted_at);
    }
}
