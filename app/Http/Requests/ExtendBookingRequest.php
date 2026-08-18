<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class ExtendBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UserRole::allowsOperational($this->user()?->role);
    }

    public function rules(): array
    {
        return [
            'hours'            => 'required_without:days|nullable|integer|min:1',
            'days'             => 'required_without:hours|nullable|integer|min:1',
            'payment_method'   => 'required|in:cash,gcash,card,bank_transfer,maya,other_ewallet,other,split',
            'cash_amount'      => 'nullable|numeric|min:0',
            'gcash_amount'     => 'nullable|numeric|min:0',
            'gcash_ref'        => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:50',
            'transaction_notes' => 'nullable|string|max:500',
        ];
    }
}
