<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'booking_id',
        'transaction_type',
        'description',
        'amount',
        'payment_method',
        'cash_amount',
        'gcash_amount',
        'bank_amount',
        'gcash_ref',
        'bank_ref',
        'processed_by',
        'or_number',
    ];

    protected $casts = [
        'amount' => 'float',
        'cash_amount' => 'float',
        'gcash_amount' => 'float',
        'bank_amount' => 'float',
        'or_number' => 'integer',
    ];

    protected $appends = ['formatted_or_number'];

    protected static function booted()
    {
        static::creating(function ($transaction) {
            if ($transaction->amount > 0 && in_array($transaction->payment_method, ['cash', 'gcash', 'card', 'bank_transfer', 'split'])) {
                // Fetch current OR sequence in a transaction to avoid race conditions
                \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
                    $setting = \App\Models\Setting::where('key', 'or_sequence')->lockForUpdate()->first();
                    $sequence = $setting ? (int)$setting->value : 1;
                    $transaction->or_number = $sequence;
                    
                    if ($setting) {
                        $setting->value = (string)($sequence + 1);
                        $setting->save();
                    } else {
                        \App\Models\Setting::create(['key' => 'or_sequence', 'value' => (string)($sequence + 1)]);
                    }
                });
            }
        });
    }

    public function getFormattedOrNumberAttribute()
    {
        if (!$this->or_number) return null;
        $prefix = \App\Models\Setting::getValue('or_prefix', 'OR');
        return $prefix . '-' . str_pad($this->or_number, 6, '0', STR_PAD_LEFT);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Alias for cashier() — used by eager loading in BookingController (transactions.processedBy).
     */
    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function inventoryUsages()
    {
        return $this->hasMany(InventoryUsage::class);
    }
}
