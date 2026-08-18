<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_variance_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->constrained('shift_sessions')->cascadeOnDelete();
            $table->string('drawer', 16);
            $table->string('variance_type', 16);
            $table->string('resolution_type', 64);
            $table->decimal('amount', 10, 2);
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('cash_received_into_shift_id')
                ->nullable()
                ->constrained('shift_sessions')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_session_id', 'drawer', 'status'], 'svr_shift_drawer_status_idx');
            $table->index(['cash_received_into_shift_id', 'status'], 'svr_received_shift_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_variance_resolutions');
    }
};
