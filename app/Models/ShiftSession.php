<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSession extends Model
{
    protected $fillable = [
        'user_id',
        'shift_code',
        'scheduled_start',
        'scheduled_end',
        'started_at',
        'ended_at',
        'opening_cash',
        'closing_cash',
        'opening_denominations',
        'closing_denominations',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'opening_cash' => 'float',
        'closing_cash' => 'float',
        'opening_denominations' => 'array',
        'closing_denominations' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
