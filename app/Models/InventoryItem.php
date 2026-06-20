<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'item_name',
        'category',
        'unit',
        'current_stock',
        'minimum_stock',
        'unit_cost',
        'selling_price',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'current_stock' => 'integer',
        'minimum_stock' => 'integer',
        'unit_cost' => 'float',
        'selling_price' => 'float',
        'is_active' => 'boolean',
    ];

    public function usages()
    {
        return $this->hasMany(InventoryUsage::class, 'item_id');
    }
}
