<?php

namespace App\Services;

use App\Models\ShiftSession;
use App\Models\User;

class ShiftService
{
    public static function activeRegister(): ?ShiftSession
    {
        return ShiftSession::with('user')
            ->active()
            ->orderBy('started_at')
            ->first();
    }

    /**
     * Ensure the user has an active shift unless they are an admin.
     * Returns true if valid or admin, false if invalid shift.
     */
    public static function requireActiveShift(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $activeShift = self::activeRegister();

        return $activeShift !== null && $activeShift->user_id === $user->id;
    }

    public static function activeRegisterId(): ?int
    {
        $register = self::activeRegister();

        return $register?->id;
    }

    /**
     * Front Desk may only change physical inventory while assigned to the
     * hotel's single active register. Admin may proceed without a register.
     */
    public static function assertCanChangeTrackedInventory(User $user, ?string $message = null): void
    {
        if (self::requireActiveShift($user)) {
            return;
        }

        abort(403, $message ?: 'An active Front Desk register is required to change tracked inventory.');
    }
}
