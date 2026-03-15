<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class ChatHistory extends Model
{
    use TraitUuid;

    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'content',
        'intent',
        'entities',
        'context',
        'metadata',
        'escalated_to_support',
    ];

    protected function casts(): array
    {
        return [
            'entities' => 'array',
            'context' => 'array',
            'metadata' => 'array',
            'escalated_to_support' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}