<?php

namespace App\Services;

use App\Models\ShiftSession;
use App\Models\User;

class ShiftService
{
    /**
     * Ensure the user has an active shift unless they are an admin.
     * Returns true if valid or admin, false if invalid shift.
     * 
     * @param User $user
     * @return bool
     */
    public static function requireActiveShift(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        return $activeShift !== null;
    }
}
