<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreanceAvoirTransaction extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'credit_profile_id',
        'creance_id',
        'creance_transaction_id',
        'created_by',
        'type',
        'montant',
        'solde_avant',
        'solde_apres',
        'reference',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'solde_avant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditProfile(): BelongsTo
    {
        return $this->belongsTo(CreditProfile::class);
    }

    public function creance(): BelongsTo
    {
        return $this->belongsTo(Creance::class);
    }

    public function creanceTransaction(): BelongsTo
    {
        return $this->belongsTo(CreanceTransaction::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}