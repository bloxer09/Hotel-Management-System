<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local testing only. Sets a clean opening on-hand qty before Front Desk
 * physical opening count. Does not change catalog fields or write movements.
 */
class HotelInventoryOpeningStockSeeder extends Seeder
{
    public const OPENING_STOCK = [
        'Mineral Water 500ml' => 40,
        'Soft Drink (Cola)' => 30,
        'Beer (Local)' => 25,
        'Peanuts (Small)' => 20,
        'Chocolate Bar' => 20,
        'Shampoo Sachet' => 20,
        'Soap Bar' => 20,
    ];

    public function run(): void
    {
        foreach (self::OPENING_STOCK as $name => $qty) {
            $updated = DB::table('inventory_items')
                ->whereNull('deleted_at')
                ->where('normalized_name', InventoryItem::normalizeName($name))
                ->update(['current_stock' => $qty]);

            if ($updated === 0) {
                $this->command?->warn("Opening stock skipped; item not found: {$name}");
                continue;
            }

            $this->command?->info("{$name} current_stock = {$qty}");
        }
    }
}
