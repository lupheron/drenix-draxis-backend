<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLead extends Model
{
    protected $fillable = [
        'company',
        'monday_item_id',
        'board_id',
        'board_name',
        'group_id',
        'group_title',
        'name',
        'phone',
        'phone_normalized',
        'email',
        'email_normalized',
        'name_normalized',
        'status_label',
        'status_key',
        'notes',
        'platform',
        'position',
        'state',
        'recruiter',
        'applied_on',
        'contacted_on',
        'content_hash',
        'columns',
        'raw',
        'monday_created_at',
        'monday_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'raw' => 'array',
            'applied_on' => 'date',
            'contacted_on' => 'date',
            'monday_created_at' => 'datetime',
            'monday_updated_at' => 'datetime',
        ];
    }
}
