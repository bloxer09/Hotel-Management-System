<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryUsage extends Model
{
    protected $table = 'inventory_usage';

    protected $fillable = [
        'booking_id',
        'transaction_id',
        'item_id',
        'quantity',
        'unit_price',
        'total_price',
        'recorded_by',
        'notes',
        'shift_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'total_price' => 'float',
    ];

    protected static function booted()
    {
        static::creating(function ($usage) {
            $user = auth()->user();
            if ($user) {
                $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
                    ->whereNull('ended_at')
                    ->first();
                if ($activeShift) {
                    $usage->shift_id = $activeShift->id;
                }
            }
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function shift()
    {
        return $this->belongsTo(ShiftSession::class, 'shift_id');
    }
}
