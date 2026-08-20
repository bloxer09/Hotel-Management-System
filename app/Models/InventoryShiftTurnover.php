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
        'admin_override_at' => 'datetime',
        'is_bootstrap' => 'boolean',
        'has_manual_set' => 'boolean',
    ];

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

    public function isFrozenSnapshot(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_DISPUTED], true);
    }

    public function openingEstablished(): bool
    {
        return $this->opening_established_at !== null;
    }
}
