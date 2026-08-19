<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Support\PostedCashVoidPolicy;
use Illuminate\Foundation\Http\FormRequest;

class VoidCashActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UserRole::allowsOperational($this->user()?->role);
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
            'confirm_no_physical_movement' => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A void reason is required.',
            'confirm_no_physical_movement.accepted' => PostedCashVoidPolicy::CONFIRMATION_REQUIRED,
        ];
    }
}
