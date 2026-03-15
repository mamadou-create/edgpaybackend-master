<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class WhatsAppChatSession extends Model
{
    use TraitUuid;

    protected $table = 'whatsapp_chat_sessions';

    protected $fillable = [
        'user_phone',
        'user_id',
        'state',
        'context',
        'last_message',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
