<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TopupRequest extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'pro_id',
        'decided_by',
        'amount',
        'kind',
        'status',
        'statut_paiement',
        'idempotency_key',
        'note',
        'date_demande',
        'date_decision',
        'cancellation_reason',
        'cancelled_at',
    ];



    protected $with = [];


    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'date_demande' => 'datetime',
            'date_decision' => 'datetime',
            'cancelled_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // Relations
    public function pro()
    {
        return $this->belongsTo(User::class, 'pro_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
