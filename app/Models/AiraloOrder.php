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
        'airalo_order_id',
        'iccid',
        'qrcode_url',
        'smdp_address',
        'ac_code',
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
            'price' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
