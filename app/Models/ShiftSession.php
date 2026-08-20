<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSession extends Model
{
    public const MAIN_REGISTER_KEY = 'main-front-desk';

    protected $fillable = [
        'user_id',
        'active_register_key',
        'shift_code',
        'scheduled_start',
        'scheduled_end',
        'started_at',
        'ended_at',
        'opening_cash',
        'closing_cash',
        'opening_denominations',
        'closing_denominations',
        'opening_cash_minibar',
        'closing_cash_minibar',
        'opening_denominations_minibar',
        'closing_denominations_minibar',
        'expected_cash_rooms',
        'expected_cash_minibar',
        'variance_rooms',
        'variance_minibar',
        'expected_formula_version',
        'variance_status',
        'handover_from_shift_id',
        'handover_notes',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'opening_cash' => 'float',
        'closing_cash' => 'float',
        'opening_denominations' => 'array',
        'closing_denominations' => 'array',
        'opening_cash_minibar' => 'float',
        'closing_cash_minibar' => 'float',
        'opening_denominations_minibar' => 'array',
        'closing_denominations_minibar' => 'array',
        'expected_cash_rooms' => 'float',
        'expected_cash_minibar' => 'float',
        'variance_rooms' => 'float',
        'variance_minibar' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashMovements()
    {
        return $this->hasMany(CashMovement::class);
    }

    public function handoverFromShift()
    {
        return $this->belongsTo(self::class, 'handover_from_shift_id');
    }

    public function varianceResolutions()
    {
        return $this->hasMany(ShiftVarianceResolution::class);
    }

    public function inventoryTurnover()
    {
        return $this->hasOne(InventoryShiftTurnover::class);
    }

    public function recoveryReceipts()
    {
        return $this->hasMany(ShiftVarianceResolution::class, 'cash_received_into_shift_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }
}
