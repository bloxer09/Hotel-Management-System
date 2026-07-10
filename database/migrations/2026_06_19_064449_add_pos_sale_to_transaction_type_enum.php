<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('check_in', 'check_out', 'extension', 'adjustment', 'inventory', 'pos_sale') DEFAULT 'check_in'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('check_in', 'check_out', 'extension', 'adjustment', 'inventory') DEFAULT 'check_in'");
        }
    }
};
