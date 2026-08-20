<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryShiftCountItem extends Model
{
    protected $fillable = [
        'inventory_shift_turnover_id',
        'inventory_item_id',
        'item_name',
        'unit',
        'selling_price',
        'opening_quantity',
        'restock_quantity',
        'return_quantity',
        'sold_quantity',
        'complimentary_quantity',
        'other_out_quantity',
        'manual_set_quantity',
        'expected_closing_quantity',
        'outgoing_actual_quantity',
        'variance_quantity',
        'gap_net_quantity',
        'handover_expected_quantity',
        'incoming_verified_quantity',
        'handover_difference',
    ];

    protected $casts = [
        'selling_price' => 'float',
        'opening_quantity' => 'integer',
        'restock_quantity' => 'integer',
        'return_quantity' => 'integer',
        'sold_quantity' => 'integer',
        'complimentary_quantity' => 'integer',
        'other_out_quantity' => 'integer',
        'manual_set_quantity' => 'integer',
        'expected_closing_quantity' => 'integer',
        'outgoing_actual_quantity' => 'integer',
        'variance_quantity' => 'integer',
        'gap_net_quantity' => 'integer',
        'handover_expected_quantity' => 'integer',
        'incoming_verified_quantity' => 'integer',
        'handover_difference' => 'integer',
    ];

    public function turnover()
    {
        return $this->belongsTo(InventoryShiftTurnover::class, 'inventory_shift_turnover_id');
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }
}
