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
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('bank_amount', 10, 2)->default(0.00)->after('gcash_amount');
            $table->string('bank_ref')->nullable()->after('gcash_ref');
        });

        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->nullable()->after('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['bank_amount', 'bank_ref']);
        });

        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->dropColumn('transaction_id');
        });
    }
};
