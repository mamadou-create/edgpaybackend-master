<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiraloOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'package_id',
        'package_title',
        'destination',
        'data_volume',
        'validity_days',
        'operator_name',
        'airalo_order_id',
        'quantity',
        'price',
        'currency',
        'status',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'validity_days' => 'integer',
            'price' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
