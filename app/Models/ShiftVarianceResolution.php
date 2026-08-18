<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftVarianceResolution extends Model
{
    public const DRAWER_ROOM = 'room';

    public const DRAWER_MINIBAR = 'minibar';

    public const VARIANCE_SHORTAGE = 'shortage';

    public const VARIANCE_OVERAGE = 'overage';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_SHORTAGE_RECOVERY = 'shortage_recovery';

    public const TYPE_TRANSACTION_CORRECTION = 'transaction_correction';

    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';

    public const TYPE_OTHER = 'other';

    public const TYPE_IDENTIFIED_OVERAGE = 'identified_overage';

    public const TYPE_APPROVED_UNIDENTIFIED_OVERAGE = 'approved_unidentified_overage';

    public const SHORTAGE_TYPES = [
        self::TYPE_SHORTAGE_RECOVERY,
        self::TYPE_TRANSACTION_CORRECTION,
        self::TYPE_ADMIN_ADJUSTMENT,
        self::TYPE_OTHER,
    ];

    public const OVERAGE_TYPES = [
        self::TYPE_IDENTIFIED_OVERAGE,
        self::TYPE_TRANSACTION_CORRECTION,
        self::TYPE_APPROVED_UNIDENTIFIED_OVERAGE,
        self::TYPE_ADMIN_ADJUSTMENT,
        self::TYPE_OTHER,
    ];

    public const ADMIN_ONLY_TYPES = [
        self::TYPE_ADMIN_ADJUSTMENT,
        self::TYPE_APPROVED_UNIDENTIFIED_OVERAGE,
    ];

    public const TYPES_WITHOUT_PHYSICAL_CASH = [
        self::TYPE_TRANSACTION_CORRECTION,
        self::TYPE_ADMIN_ADJUSTMENT,
        self::TYPE_APPROVED_UNIDENTIFIED_OVERAGE,
        self::TYPE_OTHER,
        self::TYPE_IDENTIFIED_OVERAGE,
    ];

    public const TYPES_REQUIRING_JUSTIFICATION = [
        self::TYPE_ADMIN_ADJUSTMENT,
        self::TYPE_APPROVED_UNIDENTIFIED_OVERAGE,
        self::TYPE_OTHER,
        self::TYPE_TRANSACTION_CORRECTION,
    ];

    public const DESTINATION_OFFICE_SAFE = 'office_safe';

    public const DESTINATION_ACTIVE_DRAWER = 'active_drawer';

    protected $fillable = [
        'shift_session_id',
        'drawer',
        'variance_type',
        'resolution_type',
        'amount',
        'notes',
        'submitted_by',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'cash_received_into_shift_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function cashReceivedIntoShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'cash_received_into_shift_id');
    }

    public static function typesFor(string $varianceType): array
    {
        return $varianceType === self::VARIANCE_OVERAGE
            ? self::OVERAGE_TYPES
            : self::SHORTAGE_TYPES;
    }
}
