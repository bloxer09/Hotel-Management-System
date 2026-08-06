<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'front_desk', 'cashier'], true);
    }

    public function rules(): array
    {
        return [
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cash_drawer' => 'required|in:room,minibar',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
