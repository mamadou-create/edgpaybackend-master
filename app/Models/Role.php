<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use TraitUuid;

    protected $fillable = ['name', 'slug', 'description', 'is_super_admin'];
    protected $with = ['permissions'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('access_level');
    }


    public function hasPermission($permissionSlug)
    {
        $permission = $this->permissions->where('slug', $permissionSlug)->first();

        if (!$permission) return false;

        return $permission->pivot->access_level === 'oui';
    }

    public function hasLimitedPermission($permissionSlug)
    {
        $permission = $this->permissions->where('slug', $permissionSlug)->first();

        if (!$permission) return false;

        return in_array($permission->pivot->access_level, ['oui', 'limité']);
    }
}
