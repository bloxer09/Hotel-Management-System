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
        // Add 'reserved' status to status enum column in bookings table
        DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM('active', 'checked_out', 'cancelled', 'no_show', 'reserved') NOT NULL DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert bookings status column (warning: if rows have status 'reserved' it will cause mysql errors or truncates)
        DB::statement("ALTER TABLE bookings MODIFY COLUMN status ENUM('active', 'checked_out', 'cancelled', 'no_show') NOT NULL DEFAULT 'active'");
    }
};
