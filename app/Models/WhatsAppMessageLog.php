<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageLog extends Model
{
    use TraitUuid;

    protected $table = 'whatsapp_message_logs';

    protected $fillable = [
        'user_id',
        'user_phone',
        'session_id',
        'direction',
        'message',
        'provider_message_id',
        'intent',
        'payload',
        'status',
        'sent_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function session()
    {
        return $this->belongsTo(WhatsAppChatSession::class, 'session_id');
    }
}
