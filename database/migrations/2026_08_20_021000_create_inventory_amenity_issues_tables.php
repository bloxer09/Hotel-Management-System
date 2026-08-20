<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_amenity_issues', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('shift_session_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->string('issue_context', 16);
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'issue_context'], 'iai_booking_context_idx');
        });

        Schema::create('inventory_amenity_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amenity_issue_id')->constrained('inventory_amenity_issues')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('stock_movement_id')->nullable()->constrained('inventory_stock_movements')->nullOnDelete();
            $table->string('initial_claim_key', 64)->nullable()->unique('iai_items_initial_claim_unique');
            $table->timestamps();

            $table->index(['amenity_issue_id', 'inventory_item_id'], 'iai_items_issue_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_amenity_issue_items');
        Schema::dropIfExists('inventory_amenity_issues');
    }
};
