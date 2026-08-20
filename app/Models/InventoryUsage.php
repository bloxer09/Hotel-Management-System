<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryUsage extends Model
{
    protected $table = 'inventory_usage';

    protected $fillable = [
        'booking_id',
        'transaction_id',
        'origin_transaction_id',
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
            if ($usage->shift_id) {
                return;
            }

            $usage->shift_id = \App\Services\ShiftService::activeRegisterId();
        });
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function originTransaction()
    {
        return $this->belongsTo(Transaction::class, 'origin_transaction_id');
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

    /**
     * Commercial usages on a stay that still belong in checkout inventory due.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function unsettledForCheckout(int $bookingId)
    {
        return app(\App\Services\InventoryUsageSettlementService::class)
            ->unsettledForCheckout($bookingId);
    }

    public function isSettled(): bool
    {
        return app(\App\Services\InventoryUsageSettlementService::class)
            ->isSettled($this);
    }
}
