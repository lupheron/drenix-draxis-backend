<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'company',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
