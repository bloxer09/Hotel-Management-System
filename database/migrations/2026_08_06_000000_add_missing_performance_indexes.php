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
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['status', 'check_in'], 'bookings_status_checkin_idx');
            $table->index(['status', 'expected_check_out'], 'bookings_status_checkout_idx');
            $table->index(['group_ref'], 'bookings_group_ref_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['created_at', 'payment_method'], 'transactions_date_method_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], 'audit_logs_action_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_status_checkin_idx');
            $table->dropIndex('bookings_status_checkout_idx');
            $table->dropIndex('bookings_group_ref_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_date_method_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_date_idx');
        });
    }
};
