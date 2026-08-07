<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdditionalCash extends Model
{
    use HasFactory;

    protected $table = 'additional_cash';

    protected $fillable = [
        'income_date',
        'amount',
        'cash_drawer',
        'notes',
        'receipt_path',
        'recorded_by',
    ];

    protected $casts = [
        'income_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
