<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryShiftTurnover extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COUNTING = 'counting';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DISPUTED = 'disputed';

    public const FORMULA_VERSION = 'inventory_turnover_v1';

    public const SOURCE_TYPE = 'inventory_turnover';

    public const SOURCE_OPENING = 'inventory_turnover_opening';

    public const SOURCE_RESOLUTION = 'inventory_handover_resolution';

    public const RESOLUTION_ACCEPT_INCOMING = 'accept_incoming';

    public const RESOLUTION_REQUIRE_RECOUNT = 'require_recount';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'OPEN',
        self::STATUS_COUNTING => 'COUNTING',
        self::STATUS_SUBMITTED => 'SUBMITTED',
        self::STATUS_DISPUTED => 'DISPUTED',
        self::STATUS_ACCEPTED => 'ACCEPTED',
    ];

    public const STATUS_DESCRIPTIONS = [
        self::STATUS_OPEN => 'Turnover has not yet been counted.',
        self::STATUS_COUNTING => 'Physical inventory count is in progress.',
        self::STATUS_SUBMITTED => 'Outgoing count submitted. Waiting for incoming verification.',
        self::STATUS_DISPUTED => 'Incoming verification does not match expected physical handover.',
        self::STATUS_ACCEPTED => 'Inventory handover has been verified and accepted.',
    ];

    protected $fillable = [
        'shift_session_id',
        'status',
        'freeze_started_at',
        'submitted_at',
        'accepted_at',
        'opening_established_at',
        'counted_by',
        'accepted_by',
        'notes',
        'disputed_reason',
        'disputed_at',
        'disputed_by',
        'resolution_type',
        'resolution_notes',
        'resolved_at',
        'resolved_by',
        'recount_requested_at',
        'recount_requested_by',
        'admin_override_reason',
        'admin_override_by',
        'admin_override_at',
        'formula_version',
        'is_bootstrap',
        'has_manual_set',
    ];

    protected $casts = [
        'freeze_started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'opening_established_at' => 'datetime',
        'disputed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'recount_requested_at' => 'datetime',
        'admin_override_at' => 'datetime',
        'is_bootstrap' => 'boolean',
        'has_manual_set' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $turnover) {
            if ($turnover->isFrozenSnapshot()) {
                return false;
            }

            return true;
        });
    }

    public function shiftSession()
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function items()
    {
        return $this->hasMany(InventoryShiftCountItem::class, 'inventory_shift_turnover_id');
    }

    public function countedBy()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function disputedBy()
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function recountRequestedBy()
    {
        return $this->belongsTo(User::class, 'recount_requested_by');
    }

    public function adminOverrideBy()
    {
        return $this->belongsTo(User::class, 'admin_override_by');
    }

    public function isFrozenSnapshot(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_DISPUTED], true);
    }

    public function openingEstablished(): bool
    {
        return $this->opening_established_at !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? strtoupper((string) $this->status);
    }

    public function statusDescription(): string
    {
        return self::STATUS_DESCRIPTIONS[$this->status] ?? '';
    }
}
