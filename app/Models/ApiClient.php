<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;


class ApiClient extends Authenticatable implements JWTSubject
{
    use HasFactory, TraitUuid;

    protected $fillable = [
        'client_id',
        'client_secret',
        'name',
        'scopes',
        'revoked',
        'expires_at',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'scopes' => 'array',
        'revoked' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'client_id' => $this->client_id,
            'scopes' => $this->scopes,
            'type' => 'client_credentials'
        ];
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
        return $this->client_secret;
    }

    /**
     * Mutateur pour hasher automatiquement le client_secret
     */
    public function setClientSecretAttribute($value)
    {
        // ⚠️ Ne hash que si la valeur n'est pas déjà hashée
        if (!empty($value) && !preg_match('/^\$2[ayb]\$.{56}$/', $value)) {
            $this->attributes['client_secret'] = Hash::make($value);
        } else {
            $this->attributes['client_secret'] = $value;
        }
    }

    public static function generateClientCredentials()
    {
        return [
            'client_id' => "mding-" . Str::uuid()->toString(),
            'client_secret' => Str::random(64),
        ];
    }

    public function isValid()
    {
        return !$this->revoked && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
