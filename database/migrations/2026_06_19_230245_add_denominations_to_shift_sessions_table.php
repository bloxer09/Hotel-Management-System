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
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->json('opening_denominations')->nullable()->after('opening_cash');
            $table->json('closing_denominations')->nullable()->after('closing_cash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropColumn(['opening_denominations', 'closing_denominations']);
        });
    }
};
