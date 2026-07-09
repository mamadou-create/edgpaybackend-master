<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    use TraitUuid;

    protected $fillable = [
        'id',
        'user_id',
        'service',
        'endpoint',
        'method',
        'status_code',
        'duration_ms',
        'correlation_id',
        'idempotency_key',
        'request_ip',
        'request_headers',
        'request_body',
        'response_body',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_body' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
