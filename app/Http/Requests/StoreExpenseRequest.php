<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'front_desk', 'cashier'], true);
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('category')) {
            $this->merge([
                'category' => is_string($this->input('category'))
                    ? trim($this->input('category'))
                    : $this->input('category'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cash_drawer' => 'required|in:room,minibar',
            'category' => 'required|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
