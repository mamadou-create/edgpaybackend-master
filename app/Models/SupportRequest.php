<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class SupportRequest extends Model
{
    use TraitUuid;

    protected $fillable = [
        'user_id',
        'source',
        'reason',
        'status',
        'last_user_message',
        'transcript',
        'metadata',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'metadata' => 'array',
            'transferred_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}