<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update bookings foreign key from cascade to restrict on room delete
        Schema::table('bookings', function (Blueprint $table) {
            // Drop foreign key by name or array notation
            $table->dropForeign(['room_id']);

            // Re-create the foreign key constraint with restrict
            $table->foreign('room_id')
                ->references('id')
                ->on('rooms')
                ->onDelete('restrict');

            // Add performance indexes on bookings table
            $table->index('status');
            $table->index('check_in');
            $table->index('expected_check_out');
        });

        // 2. Add search indexes on guest_profiles table
        Schema::table('guest_profiles', function (Blueprint $table) {
            $table->index('full_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_profiles', function (Blueprint $table) {
            $table->dropIndex(['full_name']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['check_in']);
            $table->dropIndex(['expected_check_out']);

            $table->dropForeign(['room_id']);
            $table->foreign('room_id')
                ->references('id')
                ->on('rooms')
                ->onDelete('cascade');
        });
    }
};
