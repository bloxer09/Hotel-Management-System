<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesShiftVarianceResolution;
use App\Models\ShiftVarianceResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveShiftVarianceResolutionRequest extends FormRequest
{
    use ValidatesShiftVarianceResolution;

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin->value;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeTrimmedVarianceFields();
    }

    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'recovery_destination' => ['nullable', Rule::in([
                ShiftVarianceResolution::DESTINATION_OFFICE_SAFE,
                ShiftVarianceResolution::DESTINATION_ACTIVE_DRAWER,
            ])],
            'receive_into_active_drawer' => ['sometimes', 'boolean'],
        ];
    }
}
