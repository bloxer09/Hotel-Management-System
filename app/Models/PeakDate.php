<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeakDate extends Model
{
    protected $fillable = [
        'date_from',
        'date_to',
        'label',
        'surcharge_amount',
        'surcharge_type',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'surcharge_amount' => 'float',
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
