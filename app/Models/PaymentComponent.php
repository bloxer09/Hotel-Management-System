<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentComponent extends Model
{
    protected $fillable = [
        'payment_id',
        'payment_method_code',
        'amount',
        'reference_number',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
