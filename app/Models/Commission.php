<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Commission extends Model
{
    use TraitUuid, SoftDeletes;


    protected $fillable = [
        'key',
        'value'
    ];


    protected $with = [];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'deleted_at' => 'datetime',
        ];
    }
}
