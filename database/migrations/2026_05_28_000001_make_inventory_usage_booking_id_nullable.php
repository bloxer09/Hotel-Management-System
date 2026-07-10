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
            // Disable foreign key checks, alter column to nullable, and re-enable
            DB::statement("SET FOREIGN_KEY_CHECKS=0");
            DB::statement("ALTER TABLE inventory_usage MODIFY COLUMN booking_id BIGINT UNSIGNED NULL");
            DB::statement("SET FOREIGN_KEY_CHECKS=1");
        } else {
            Schema::table('inventory_usage', function (Blueprint $table) {
                $table->unsignedBigInteger('booking_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Revert column back to NOT NULL
            DB::statement("SET FOREIGN_KEY_CHECKS=0");
            DB::statement("ALTER TABLE inventory_usage MODIFY COLUMN booking_id BIGINT UNSIGNED NOT NULL");
            DB::statement("SET FOREIGN_KEY_CHECKS=1");
        } else {
            Schema::table('inventory_usage', function (Blueprint $table) {
                $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            });
        }
    }
};
