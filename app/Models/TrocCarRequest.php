<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrocCarRequest extends Model
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
        'source_brand',
        'source_model',
        'source_year',
        'source_fuel',
        'source_transmission',
        'mileage_km',
        'condition',
        'condition_details',
        'image_url',
        'image_analysis',
        'estimated_price',
        'target_brand',
        'target_model',
        'target_year',
        'target_fuel',
        'target_transmission',
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
            'source_year' => 'integer',
            'target_year' => 'integer',
            'mileage_km' => 'integer',
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
