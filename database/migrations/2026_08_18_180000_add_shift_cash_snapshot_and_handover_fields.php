<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->decimal('expected_cash_rooms', 10, 2)->nullable()->after('closing_denominations_minibar');
            $table->decimal('expected_cash_minibar', 10, 2)->nullable()->after('expected_cash_rooms');
            $table->decimal('variance_rooms', 10, 2)->nullable()->after('expected_cash_minibar');
            $table->decimal('variance_minibar', 10, 2)->nullable()->after('variance_rooms');
            $table->string('expected_formula_version', 32)->nullable()->after('variance_minibar');
            $table->string('variance_status', 32)->nullable()->after('expected_formula_version');
            $table->foreignId('handover_from_shift_id')
                ->nullable()
                ->after('variance_status')
                ->constrained('shift_sessions')
                ->nullOnDelete();
            $table->text('handover_notes')->nullable()->after('handover_from_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_from_shift_id');
            $table->dropColumn([
                'expected_cash_rooms',
                'expected_cash_minibar',
                'variance_rooms',
                'variance_minibar',
                'expected_formula_version',
                'variance_status',
                'handover_notes',
            ]);
        });
    }
};
