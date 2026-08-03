<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use TraitUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_body' => 'encrypted:array',
        ];
    }
}