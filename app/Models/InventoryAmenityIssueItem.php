<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAmenityIssueItem extends Model
{
    protected $fillable = [
        'amenity_issue_id',
        'inventory_item_id',
        'quantity',
        'stock_movement_id',
        'initial_claim_key',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_movement_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            return false;
        });
    }

    public function issue()
    {
        return $this->belongsTo(InventoryAmenityIssue::class, 'amenity_issue_id');
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function stockMovement()
    {
        return $this->belongsTo(InventoryStockMovement::class, 'stock_movement_id');
    }

    public static function claimKey(int $bookingId, int $itemId): string
    {
        return $bookingId.':'.$itemId;
    }
}
