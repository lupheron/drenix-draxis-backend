<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingCentralMessage extends Model
{
    protected $table = 'ringcentral_messages';

    protected $fillable = [
        'user_id',
        'company',
        'external_id',
        'conversation_id',
        'direction',
        'body',
        'from_number',
        'to_number',
        'peer_number',
        'peer_name',
        'sent_at',
        'status',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
