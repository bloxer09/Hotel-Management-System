<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UserRole::allowsOperational($this->user()?->role);
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
        if ($this->exists('notes')) {
            $this->merge([
                'notes' => is_string($this->input('notes')) ? trim($this->input('notes')) : $this->input('notes'),
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
            'notes' => 'required|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'A reason / description is required.',
        ];
    }
}
