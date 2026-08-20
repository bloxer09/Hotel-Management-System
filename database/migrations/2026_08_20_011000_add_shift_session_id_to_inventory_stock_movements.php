<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->foreignId('shift_session_id')
                ->nullable()
                ->after('performed_by')
                ->constrained('shift_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_session_id');
        });
    }
};
