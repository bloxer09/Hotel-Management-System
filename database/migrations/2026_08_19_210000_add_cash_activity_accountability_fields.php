<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
            $table->string('status', 32)->default('POSTED')->after('recorded_by');
            $table->foreignId('shift_session_id')->nullable()->after('status')->constrained('shift_sessions')->restrictOnDelete();
            $table->foreignId('posted_shift_session_id')->nullable()->after('shift_session_id')->constrained('shift_sessions')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('posted_shift_session_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->foreignId('posted_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->foreignId('voided_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->text('void_reason')->nullable()->after('voided_at');
        });

        Schema::table('additional_cash', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
            $table->string('status', 32)->default('POSTED')->after('recorded_by');
            $table->foreignId('shift_session_id')->nullable()->after('status')->constrained('shift_sessions')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->after('shift_session_id')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->text('void_reason')->nullable()->after('voided_at');
        });

        foreach (DB::table('expenses')->orderBy('id')->get(['id']) as $row) {
            DB::table('expenses')->where('id', $row->id)->update([
                'reference' => sprintf('EXP-LEGACY-%08d', $row->id),
                'status' => 'POSTED',
            ]);
        }

        foreach (DB::table('additional_cash')->orderBy('id')->get(['id']) as $row) {
            DB::table('additional_cash')->where('id', $row->id)->update([
                'reference' => sprintf('ADC-LEGACY-%08d', $row->id),
                'status' => 'POSTED',
            ]);
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->unique('reference');
            $table->index('status');
            $table->index(['status', 'posted_shift_session_id'], 'expenses_posted_shift_idx');
            $table->index(['status', 'shift_session_id'], 'expenses_origin_shift_idx');
        });

        Schema::table('additional_cash', function (Blueprint $table) {
            $table->unique('reference');
            $table->index('status');
            $table->index(['status', 'shift_session_id'], 'additional_cash_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropIndex('expenses_posted_shift_idx');
            $table->dropIndex('expenses_origin_shift_idx');
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('posted_shift_session_id');
            $table->dropConstrainedForeignId('shift_session_id');
            $table->dropColumn([
                'reference',
                'status',
                'reviewed_at',
                'review_notes',
                'posted_at',
                'voided_at',
                'void_reason',
            ]);
        });

        Schema::table('additional_cash', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropIndex('additional_cash_shift_idx');
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('shift_session_id');
            $table->dropColumn(['reference', 'status', 'voided_at', 'void_reason']);
        });
    }
};
