<?php

namespace App\Http\Requests;

use App\Services\BookingService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'front_desk'], true);
    }

    public function rules(): array
    {
        return [
            'room_id'           => 'required|exists:rooms,id',
            'guest_name'        => 'required|string|max:100',
            'guest_contact'     => 'nullable|string|max:20',
            'guest_id_type'     => 'nullable|string|max:50',
            'guest_id_number'   => 'nullable|string|max:50',
            'id_image'          => 'nullable|mimes:jpg,jpeg,png,webp|max:5120',
            'guest_email'       => 'nullable|email|max:100',
            'guest_address'     => 'nullable|string',
            'num_guests'        => 'required|integer|min:1',
            'check_in'          => 'nullable|date',
            'booking_type'      => 'required|in:overnight,short_time',
            'num_nights'        => 'nullable|integer|min:1',
            'short_time_hours'  => 'nullable|integer|in:3,6,12,24',
            'discount_type'     => 'nullable|string',
            'discount_amount'   => 'nullable|numeric|min:0',
            'promo_code'        => 'nullable|string',
            'notes'             => 'nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $booking = $this->route('booking');
            $checkIn = $this->input('check_in') ?: optional($booking)->getRawOriginal('check_in');
            $message = BookingService::stayTypeMismatchMessage(
                $checkIn,
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
