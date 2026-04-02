<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrocRequest extends Model
{
    use TraitUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'source_model',
        'source_storage',
        'battery',
        'condition',
        'condition_details',
        'image_url',
        'image_analysis',
        'estimated_price',
        'target_model',
        'target_storage',
        'target_price',
        'difference',
        'currency',
        'offer_message',
        'status',
        'admin_notes',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'battery' => 'integer',
            'condition_details' => 'array',
            'image_analysis' => 'array',
            'estimated_price' => 'float',
            'target_price' => 'float',
            'difference' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_REVIEWED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}