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
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->decimal('opening_cash_minibar', 10, 2)->default(0.00)->after('opening_denominations');
            $table->json('opening_denominations_minibar')->nullable()->after('opening_cash_minibar');
            
            $table->decimal('closing_cash_minibar', 10, 2)->default(0.00)->after('closing_denominations');
            $table->json('closing_denominations_minibar')->nullable()->after('closing_cash_minibar');
        });

        // Retroactively link inventory usages to their booking's check_out transactions
        $usages = DB::table('inventory_usage')
            ->whereNull('transaction_id')
            ->whereNotNull('booking_id')
            ->get();

        foreach ($usages as $usage) {
            $checkoutTx = DB::table('transactions')
                ->where('booking_id', $usage->booking_id)
                ->where('transaction_type', 'check_out')
                ->orderBy('created_at', 'asc')
                ->first();
            if ($checkoutTx) {
                DB::table('inventory_usage')
                    ->where('id', $usage->id)
                    ->update(['transaction_id' => $checkoutTx->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'opening_cash_minibar',
                'opening_denominations_minibar',
                'closing_cash_minibar',
                'closing_denominations_minibar'
            ]);
        });
    }
};
