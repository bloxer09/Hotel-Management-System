<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_INITIAL_STOCK = 'initial_stock';

    public const TYPE_MANUAL_ADD = 'manual_add';

    public const TYPE_RESTOCK = 'restock';

    public const TYPE_MANUAL_SUBTRACT = 'manual_subtract';

    public const TYPE_MANUAL_SET = 'manual_set';

    public const TYPE_POS_SALE = 'pos_sale';

    public const TYPE_BOOKING_USAGE = 'booking_usage';

    public const TYPE_BOOKING_REVERSAL = 'booking_reversal';

    public const TYPE_COMPLIMENTARY_AMENITY = 'complimentary_amenity';

    public const TYPE_INVENTORY_VARIANCE = 'inventory_variance';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_INTERNAL_USE = 'internal_use';

    public const TYPE_OTHER_AUTHORIZED_OUT = 'other_authorized_out';

    protected $fillable = [
        'inventory_item_id',
        'inventory_change_request_id',
        'movement_type',
        'quantity_change',
        'stock_before',
        'stock_after',
        'source_type',
        'source_id',
        'performed_by',
        'shift_session_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'source_id' => 'integer',
        'shift_session_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function changeRequest()
    {
        return $this->belongsTo(InventoryChangeRequest::class, 'inventory_change_request_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function shiftSession()
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    /**
     * Map a stock-adjustment request to the movement type written for NEW rows.
     * Legacy `manual_add` history is never rewritten.
     */
    public static function typeForAdjustment(string $adjustmentType): string
    {
        return match ($adjustmentType) {
            InventoryChangeRequest::TYPE_ADD => self::TYPE_RESTOCK,
            InventoryChangeRequest::TYPE_SUBTRACT => self::TYPE_MANUAL_SUBTRACT,
            InventoryChangeRequest::TYPE_SET => self::TYPE_MANUAL_SET,
            default => self::TYPE_MANUAL_SET,
        };
    }

    public static function reportingClass(string $type): string
    {
        return match ($type) {
            self::TYPE_RESTOCK, self::TYPE_MANUAL_ADD => 'inflow_add',
            self::TYPE_MANUAL_SUBTRACT => 'outflow_subtract',
            self::TYPE_MANUAL_SET => 'manual_set',
            self::TYPE_INITIAL_STOCK => 'initial_stock',
            self::TYPE_POS_SALE => 'pos_sale',
            self::TYPE_BOOKING_USAGE => 'booking_usage',
            self::TYPE_BOOKING_REVERSAL => 'booking_reversal',
            self::TYPE_COMPLIMENTARY_AMENITY => 'complimentary_amenity',
            default => $type,
        };
    }

    public static function record(array $attributes): self
    {
        $attributes['created_at'] ??= now();

        return self::create($attributes);
    }
}
