<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    public const TYPES = ['cashier_transfer', 'withdrawal'];

    protected $fillable = [
        'shift_session_id',
        'movement_type',
        'cash_drawer',
        'amount',
        'description',
        'moved_at',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'moved_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
