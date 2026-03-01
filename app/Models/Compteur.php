<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compteur extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'client_id',
        'email',
        'phone',
        'display_name',
        'compteur',
        'type_compteur',
    ];


    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
