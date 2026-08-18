<?php

namespace App\Http\Requests\Concerns;

use App\Models\ShiftVarianceResolution;
use Illuminate\Validation\Validator;

trait ValidatesShiftVarianceResolution
{
    protected function mergeTrimmedVarianceFields(): void
    {
        $merge = [];

        if ($this->exists('notes')) {
            $merge['notes'] = is_string($this->input('notes'))
                ? trim($this->input('notes'))
                : $this->input('notes');
        }

        if ($this->exists('transaction_reference')) {
            $merge['transaction_reference'] = is_string($this->input('transaction_reference'))
                ? trim($this->input('transaction_reference'))
                : $this->input('transaction_reference');
        }

        if ($this->exists('recovery_destination')) {
            $merge['recovery_destination'] = is_string($this->input('recovery_destination'))
                ? trim($this->input('recovery_destination'))
                : $this->input('recovery_destination');
        }

        if ($this->exists('receive_into_active_drawer')) {
            $merge['receive_into_active_drawer'] = filter_var(
                $this->input('receive_into_active_drawer'),
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    protected function afterVarianceValidation(Validator $validator): void
    {
        $type = (string) $this->input('resolution_type');
        $notes = trim((string) $this->input('notes', ''));
        $reference = trim((string) $this->input('transaction_reference', ''));
        $destination = $this->resolvedRecoveryDestination();

        if ($this->requiresJustification($type, $reference) && mb_strlen($notes) < 3) {
            $validator->errors()->add(
                'notes',
                $type === ShiftVarianceResolution::TYPE_TRANSACTION_CORRECTION
                    ? 'Transaction correction requires notes or a concrete transaction reference.'
                    : 'A justification note is required for this resolution type.'
            );
        }

        if (
            $destination === ShiftVarianceResolution::DESTINATION_ACTIVE_DRAWER
            && $type !== ShiftVarianceResolution::TYPE_SHORTAGE_RECOVERY
        ) {
            $validator->errors()->add(
                'recovery_destination',
                'Only shortage recovery can be received into a Front Desk drawer. Accounting resolutions do not move cash.'
            );
        }
    }

    protected function requiresJustification(string $type, string $reference): bool
    {
        if ($type === ShiftVarianceResolution::TYPE_TRANSACTION_CORRECTION && $reference !== '') {
            return false;
        }

        return $type !== '';
    }

    protected function resolvedRecoveryDestination(): string
    {
        $destination = (string) $this->input('recovery_destination', '');
        if ($destination === ShiftVarianceResolution::DESTINATION_ACTIVE_DRAWER
            || $destination === ShiftVarianceResolution::DESTINATION_OFFICE_SAFE) {
            return $destination;
        }

        return $this->boolean('receive_into_active_drawer')
            ? ShiftVarianceResolution::DESTINATION_ACTIVE_DRAWER
            : ShiftVarianceResolution::DESTINATION_OFFICE_SAFE;
    }
}
