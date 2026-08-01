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
}
