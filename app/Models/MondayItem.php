<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MondayItem extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'external_id',
        'board_id',
        'board_name',
        'board_kind',
        'group_title',
        'metric_type',
        'item_name',
        'source_label',
        'metric_date',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
