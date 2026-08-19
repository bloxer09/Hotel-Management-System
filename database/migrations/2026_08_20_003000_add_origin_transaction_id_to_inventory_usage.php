<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->foreignId('origin_transaction_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        DB::table('inventory_usage')
            ->whereNull('origin_transaction_id')
            ->whereIn('transaction_id', function ($query) {
                $query->select('id')
                    ->from('transactions')
                    ->where('transaction_type', 'pos_sale');
            })
            ->update([
                'origin_transaction_id' => DB::raw('transaction_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('inventory_usage', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_transaction_id');
        });
    }
};
