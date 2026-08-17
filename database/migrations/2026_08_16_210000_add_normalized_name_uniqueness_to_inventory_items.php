<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $normalize = static function (?string $name): string {
            $collapsed = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

            if ($collapsed === '') {
                return '';
            }

            return mb_strtolower($collapsed, 'UTF-8');
        };

        $existingRows = DB::table('inventory_items')->get(['id', 'item_name', 'category', 'deleted_at']);
        $duplicateGroups = [];

        foreach ($existingRows as $row) {
            if ($row->deleted_at !== null) {
                continue;
            }

            $key = $row->category.'|'.$normalize((string) $row->item_name);
            $duplicateGroups[$key][] = $row;
        }

        $duplicateGroups = array_filter($duplicateGroups, fn (array $group) => count($group) > 1);
        if ($duplicateGroups !== []) {
            $details = [];
            foreach ($duplicateGroups as $key => $group) {
                $ids = implode(', ', array_map(fn ($row) => '#'.$row->id, $group));
                $names = implode(', ', array_unique(array_map(fn ($row) => '"'.$row->item_name.'"', $group)));
                $details[] = "{$key} => {$names} (IDs {$ids})";
            }

            throw new \RuntimeException(
                "Cannot add unique inventory item name constraint because duplicate active items exist. ".
                "Rename or merge these items manually, then re-run migrations. Duplicates:\n".
                implode("\n", $details)
            );
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('normalized_name', 100)->nullable()->after('item_name');
        });

        foreach (DB::table('inventory_items')->orderBy('id')->cursor() as $row) {
            DB::table('inventory_items')->where('id', $row->id)->update([
                'normalized_name' => $normalize((string) $row->item_name),
            ]);
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('normalized_name', 100)->nullable(false)->change();
        });

        DB::statement("
            ALTER TABLE inventory_items
            ADD COLUMN active_name_key VARCHAR(160)
                GENERATED ALWAYS AS (
                    IF(deleted_at IS NULL, CONCAT(`category`, '|', `normalized_name`), NULL)
                ) STORED
        ");

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unique('active_name_key', 'inventory_items_active_name_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inventory_items_active_name_key_unique');
        });

        DB::statement('ALTER TABLE inventory_items DROP COLUMN active_name_key');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('normalized_name');
        });
    }
};
