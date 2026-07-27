<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'receipt_number',
        'received_at',
        'payer_name',
        'payer_contact',
        'payment_method_code',
        'reference_number',
        'amount',
        'payment_type',
        'status',
        'recorded_by',
        'verified_by',
        'verified_at',
        'shift_id',
        'remarks',
        'original_payment_id',
        'legacy_transaction_id',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'amount' => 'float',
    ];

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function components()
    {
        return $this->hasMany(PaymentComponent::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function shift()
    {
        return $this->belongsTo(ShiftSession::class);
    }

    public function originalPayment()
    {
        return $this->belongsTo(Payment::class, 'original_payment_id');
    }

    public function refunds()
    {
        return $this->hasMany(Payment::class, 'original_payment_id');
    }
}
