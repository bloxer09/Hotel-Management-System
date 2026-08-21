<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_shift_turnovers', function (Blueprint $table) {
            $table->timestamp('disputed_at')->nullable()->after('disputed_reason');
            $table->foreignId('disputed_by')->nullable()->after('disputed_at')->constrained('users')->nullOnDelete();
            $table->string('resolution_type', 32)->nullable()->after('disputed_by');
            $table->string('resolution_notes', 500)->nullable()->after('resolution_type');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('recount_requested_at')->nullable()->after('resolved_by');
            $table->foreignId('recount_requested_by')->nullable()->after('recount_requested_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_shift_turnovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disputed_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('recount_requested_by');
            $table->dropColumn([
                'disputed_at',
                'resolution_type',
                'resolution_notes',
                'resolved_at',
                'recount_requested_at',
            ]);
        });
    }
};
