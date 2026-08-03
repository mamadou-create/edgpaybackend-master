<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyConversionRateHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(fn () => throw new \RuntimeException('Un historique de taux ne peut pas être modifié.'));
        static::deleting(fn () => throw new \RuntimeException('Un historique de taux ne peut pas être supprimé.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}