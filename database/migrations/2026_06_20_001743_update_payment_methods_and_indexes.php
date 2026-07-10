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
        // 1. Update Enums for bookings and transactions
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'card', 'bank_transfer', 'split') NOT NULL DEFAULT 'cash'");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'card', 'bank_transfer', 'split', 'na') NOT NULL DEFAULT 'cash'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse Enums
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'split') NOT NULL DEFAULT 'cash'");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'split', 'na') NOT NULL DEFAULT 'cash'");
        }
    }
};
