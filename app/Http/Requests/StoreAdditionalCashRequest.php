<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdditionalCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UserRole::allowsOperational($this->user()?->role);
    }

    public function rules(): array
    {
        return [
            'income_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cash_drawer' => 'required|in:room,minibar',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
