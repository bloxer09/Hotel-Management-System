<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_shift_turnovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->unique()->constrained('shift_sessions')->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('freeze_started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('opening_established_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->string('disputed_reason', 500)->nullable();
            $table->string('admin_override_reason', 500)->nullable();
            $table->foreignId('admin_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('admin_override_at')->nullable();
            $table->string('formula_version', 32);
            $table->boolean('is_bootstrap')->default(false);
            $table->boolean('has_manual_set')->default(false);
            $table->timestamps();

            $table->index(['status', 'submitted_at'], 'ist_status_submitted_idx');
        });

        Schema::create('inventory_shift_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_shift_turnover_id')->constrained('inventory_shift_turnovers')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('item_name', 100);
            $table->string('unit', 20)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->integer('opening_quantity')->nullable();
            $table->integer('restock_quantity')->default(0);
            $table->integer('return_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->integer('complimentary_quantity')->default(0);
            $table->integer('other_out_quantity')->default(0);
            $table->integer('manual_set_quantity')->default(0);
            $table->integer('expected_closing_quantity')->nullable();
            $table->integer('outgoing_actual_quantity')->nullable();
            $table->integer('variance_quantity')->nullable();
            $table->integer('gap_net_quantity')->default(0);
            $table->integer('handover_expected_quantity')->nullable();
            $table->integer('incoming_verified_quantity')->nullable();
            $table->integer('handover_difference')->nullable();
            $table->timestamps();

            $table->unique(['inventory_shift_turnover_id', 'inventory_item_id'], 'isci_turnover_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_shift_count_items');
        Schema::dropIfExists('inventory_shift_turnovers');
    }
};
