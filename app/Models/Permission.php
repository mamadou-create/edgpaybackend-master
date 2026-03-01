<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use TraitUuid;

    protected $fillable = ['name', 'slug', 'module', 'description'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withPivot('access_level');
    }
}
