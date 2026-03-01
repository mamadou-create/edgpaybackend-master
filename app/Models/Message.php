<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use TraitUuid;

    protected $fillable = ['sender_id', 'receiver_id', 'content', 'read'];

    protected $casts = [
        'read' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];


    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
