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
        // 1. Add or_number to transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('or_number')->unsigned()->nullable()->unique()->after('booking_id');
        });

        // 2. Modify payment_method enums
        // For MySQL, we can alter the column using a raw statement
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'card', 'bank_transfer', 'split') NOT NULL DEFAULT 'cash'");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'card', 'bank_transfer', 'split', 'na') NOT NULL DEFAULT 'cash'");
        }

        // 3. Add shift_id to inventory_usage
        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('recorded_by')->constrained('shift_sessions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'split', 'na') NOT NULL DEFAULT 'cash'");
            DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('cash', 'gcash', 'split') NOT NULL DEFAULT 'cash'");
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('or_number');
        });
    }
};
