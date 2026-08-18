<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesShiftVarianceResolution;
use App\Models\ShiftVarianceResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftVarianceResolutionRequest extends FormRequest
{
    use ValidatesShiftVarianceResolution;

    public function authorize(): bool
    {
        return UserRole::allowsOperational($this->user()?->role);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeTrimmedVarianceFields();
    }

    public function rules(): array
    {
        $types = array_values(array_unique(array_merge(
            ShiftVarianceResolution::SHORTAGE_TYPES,
            ShiftVarianceResolution::OVERAGE_TYPES
        )));

        return [
            'drawer' => ['required', Rule::in([
                ShiftVarianceResolution::DRAWER_ROOM,
                ShiftVarianceResolution::DRAWER_MINIBAR,
            ])],
            'resolution_type' => ['required', Rule::in($types)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($inner) => $this->afterVarianceValidation($inner));
    }
}
