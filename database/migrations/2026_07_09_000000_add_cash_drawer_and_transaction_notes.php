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
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('cash_drawer')->default('room')->after('amount');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->string('cash_drawer')->default('room')->after('amount');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('cash_drawer');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn('cash_drawer');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
