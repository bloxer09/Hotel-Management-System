<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_source', 20)
                ->default('walk_in')
                ->after('booking_type')
                ->index();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('cash_tendered', 12, 2)->nullable()->after('amount');
            $table->decimal('change_given', 12, 2)->nullable()->after('cash_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cash_tendered', 'change_given']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['booking_source']);
            $table->dropColumn('booking_source');
        });
    }
};
