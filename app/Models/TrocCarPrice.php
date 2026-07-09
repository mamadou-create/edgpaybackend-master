<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class TrocCarPrice extends Model
{
    use TraitUuid;

    protected $fillable = [
        'brand',
        'model',
        'year',
        'fuel',
        'transmission',
        'base_price',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'base_price' => 'float',
        ];
    }
}
