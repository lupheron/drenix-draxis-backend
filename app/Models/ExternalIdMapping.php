<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalIdMapping extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'external_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
