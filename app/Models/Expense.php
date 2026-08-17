<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'expense_date',
        'amount',
        'cash_drawer',
        'notes',
        'expense_category_id',
        'receipt_path',
        'recorded_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (! $expense->expense_category_id) {
                $expense->expense_category_id = ExpenseCategory::uncategorized()->id;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
