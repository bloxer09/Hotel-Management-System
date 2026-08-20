<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'item_name',
        'normalized_name',
        'category',
        'unit',
        'current_stock',
        'minimum_stock',
        'unit_cost',
        'selling_price',
        'image_path',
        'is_active',
        'is_turnover_tracked',
    ];

    protected $casts = [
        'current_stock' => 'integer',
        'minimum_stock' => 'integer',
        'unit_cost' => 'float',
        'selling_price' => 'float',
        'is_active' => 'boolean',
        'is_turnover_tracked' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item) {
            $item->item_name = static::displayName((string) $item->item_name);
            $item->normalized_name = static::normalizeName($item->item_name);
        });
    }

    public static function displayName(?string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return $collapsed;
    }

    public static function normalizeName(?string $name): string
    {
        $display = static::displayName($name);

        if ($display === '') {
            return '';
        }

        return mb_strtolower($display, 'UTF-8');
    }

    public function isLowStock()
    {
        $stock = $this->current_stock ?? $this->quantity ?? 0;
        $min = $this->minimum_stock ?? $this->reorder_level ?? 0;

        return $stock <= $min;
    }

    public function usages()
    {
        return $this->hasMany(InventoryUsage::class, 'item_id');
    }

    public function changeRequests()
    {
        return $this->hasMany(InventoryChangeRequest::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(InventoryStockMovement::class);
    }
}
