<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class TrocPhonePrice extends Model
{
    use TraitUuid;

    protected $fillable = [
        'model',
        'storage',
        'base_price',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'float',
        ];
    }
}