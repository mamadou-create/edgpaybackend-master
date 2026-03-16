<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class UserAssistantMemory extends Model
{
    use TraitUuid;

    protected $fillable = [
        'user_id',
        'category',
        'memory_key',
        'summary',
        'payload',
        'usage_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}