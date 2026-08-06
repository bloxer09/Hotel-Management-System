<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'front_desk', 'cashier'], true);
    }

    public function rules(): array
    {
        return [
            'payment_method'                    => 'required|in:cash,gcash,card,bank_transfer,maya,other_ewallet,other,split',
            'cash_amount'                       => 'nullable|numeric|min:0',
            'gcash_amount'                      => 'nullable|numeric|min:0',
            'gcash_ref'                         => 'nullable|string|max:50',
            'reference_number'                  => 'nullable|string|max:50',
            'notes'                             => 'nullable|string',
            'transaction_notes'                 => 'nullable|string',
            'waive_late_fee'                    => 'nullable|boolean',
            'extra_charge_amount'               => 'nullable|numeric|min:0',
            'extra_charge_description'          => 'nullable|string|max:255',
            'extra_charge_separate_payment'     => 'nullable|boolean',
            'extra_charge_payment_method'       => 'nullable|in:cash,gcash,card,bank_transfer,maya,other_ewallet,other',
            'extra_charge_payment_reference'    => 'nullable|string|max:50',
        ];
    }
}
