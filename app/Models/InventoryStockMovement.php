<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_INITIAL_STOCK = 'initial_stock';

    public const TYPE_MANUAL_ADD = 'manual_add';

    public const TYPE_MANUAL_SUBTRACT = 'manual_subtract';

    public const TYPE_MANUAL_SET = 'manual_set';

    public const TYPE_POS_SALE = 'pos_sale';

    public const TYPE_BOOKING_USAGE = 'booking_usage';

    public const TYPE_BOOKING_REVERSAL = 'booking_reversal';

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
        'notes',
        'created_at',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'source_id' => 'integer',
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

    public static function record(array $attributes): self
    {
        $attributes['created_at'] ??= now();

        return self::create($attributes);
    }
}
