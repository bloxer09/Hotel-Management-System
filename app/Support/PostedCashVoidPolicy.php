<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\ShiftSession;
use App\Models\User;
use InvalidArgumentException;

class PostedCashVoidPolicy
{
    public const WARNING = 'Void only if the recorded cash movement did not actually occur. If cash physically moved, use a correction/reversal transaction instead.';

    public const CLOSED_SHIFT_MESSAGE = 'This posted record belongs to a closed shift. Ordinary void cannot change official cash. Record a physical correction on the current open shift instead.';

    public const UNLINKED_MESSAGE = 'This posted record is not linked to an open shift. Ordinary void cannot change official cash.';

    public const CONFIRMATION_REQUIRED = 'You must confirm that the recorded cash movement did not actually occur.';

    public const OPEN_SHIFT_NOTE_MESSAGE = 'This posting still belongs to an open shift. Use explicit void only if the physical cash movement did not occur.';

    public static function assertConfirmed(bool $confirmed): void
    {
        if (! $confirmed) {
            throw new InvalidArgumentException(self::CONFIRMATION_REQUIRED);
        }
    }

    public static function assertOpenAccountingShift(?ShiftSession $shift): ShiftSession
    {
        if ($shift === null) {
            throw new InvalidArgumentException(self::UNLINKED_MESSAGE);
        }
        if ($shift->ended_at !== null) {
            throw new InvalidArgumentException(self::CLOSED_SHIFT_MESSAGE);
        }

        return $shift;
    }

    public static function assertClosedOrHistorical(?ShiftSession $shift): void
    {
        if ($shift === null || $shift->ended_at !== null) {
            return;
        }

        throw new InvalidArgumentException(self::OPEN_SHIFT_NOTE_MESSAGE);
    }

    public static function assertActorMayVoid(User $actor, ShiftSession $openShift): void
    {
        if ($actor->role === UserRole::Admin->value) {
            return;
        }
        if ((int) $openShift->user_id === (int) $actor->id) {
            return;
        }

        throw new InvalidArgumentException('Only the assigned register operator or an administrator may void an erroneous open-shift posting.');
    }

    public static function assertAdmin(User $actor): void
    {
        if ($actor->role !== UserRole::Admin->value) {
            abort(403, 'Only an administrator may add a closed-shift audit note.');
        }
    }
}
