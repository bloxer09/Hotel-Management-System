<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE maintenance_tickets MODIFY status ENUM('open', 'in_progress', 'for_verification', 'closed') NOT NULL DEFAULT 'open'");
        }

        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->text('resolution_notes')->nullable()->after('notes');
            $table->string('repaired_by', 150)->nullable()->after('resolution_notes');
            $table->dateTime('repaired_at')->nullable()->after('repaired_by');
            $table->decimal('repair_cost', 10, 2)->nullable()->after('repaired_at');
            $table->string('receipt_reference', 100)->nullable()->after('repair_cost');
            $table->string('receipt_attachment_path')->nullable()->after('receipt_reference');
            $table->string('after_repair_attachment_path')->nullable()->after('receipt_attachment_path');
            $table->foreignId('verified_by')->nullable()->after('resolved_by')->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'resolution_notes',
                'repaired_by',
                'repaired_at',
                'repair_cost',
                'receipt_reference',
                'receipt_attachment_path',
                'after_repair_attachment_path',
                'verified_by',
                'verified_at',
            ]);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('maintenance_tickets')->where('status', 'for_verification')->update(['status' => 'in_progress']);
            DB::statement("ALTER TABLE maintenance_tickets MODIFY status ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open'");
        }
    }
};
