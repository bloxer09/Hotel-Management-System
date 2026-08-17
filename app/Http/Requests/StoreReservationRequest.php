<?php

namespace App\Http\Requests;

use App\Services\BookingService;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'front_desk', 'cashier'], true);
    }

    public function rules(): array
    {
        return [
            'room_ids'          => 'required|array|min:1',
            'room_ids.*'        => 'exists:rooms,id',
            'check_in'          => 'required|date',
            'guest_name'        => 'required|string|max:100',
            'guest_contact'     => 'nullable|string|max:20',
            'booker_name'       => 'nullable|string|max:150',
            'booker_contact'    => 'nullable|string|max:50',
            'guest_id_type'     => 'nullable|string|max:50',
            'guest_id_number'   => 'nullable|string|max:50',
            'id_image'          => 'nullable|mimes:jpg,jpeg,png,webp|max:5120',
            'guest_email'       => 'nullable|email|max:100',
            'guest_address'     => 'nullable|string',
            'extra_pax'         => 'nullable|array',
            'extra_pax.*'       => 'integer|min:0',
            'booking_type'      => 'required|in:overnight,short_time',
            'booking_source'    => 'required|in:walk_in,online',
            'num_nights'        => 'nullable|integer|min:1',
            'short_time_hours'  => 'nullable|integer|in:3,6,12,24',
            'discount_type'     => 'nullable|string',
            'discount_amount'   => 'nullable|numeric|min:0',
            'promo_code'        => 'nullable|string',
            'payment_ratio'     => 'nullable|in:full,half',
            'payment_method'    => 'required|in:cash,gcash,card,bank_transfer,maya,other_ewallet,other,split',
            'cash_received'     => 'required_if:payment_method,cash|nullable|numeric|min:0',
            'cash_amount'       => 'nullable|numeric|min:0',
            'gcash_amount'      => 'nullable|numeric|min:0',
            'gcash_ref'         => 'nullable|string|max:50',
            'reference_number'  => 'nullable|string|max:50',
            'notes'             => 'nullable|string',
            'transaction_notes' => 'nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $message = BookingService::stayTypeMismatchMessage(
                $this->input('check_in'),
                (string) $this->input('booking_type'),
                $this->input('num_nights') ?: 1
            );

            if ($message) {
                $validator->errors()->add('booking_type', $message);
                $validator->errors()->add('num_nights', $message);
            }
        });
    }
}
