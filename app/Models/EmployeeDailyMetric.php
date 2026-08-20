<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDailyMetric extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'minutes_on_call',
        'calls_made',
        'lates',
        'leads',
        'hires',
        'loaded',
        'follow_up',
        'rejected',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
