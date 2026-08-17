<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 32);
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->restrictOnDelete();
            $table->json('request_payload')->nullable();
            $table->string('pending_image_path')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('stock_at_request')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'inv_change_req_status_created_idx');
            $table->index(['requested_by', 'status'], 'inv_change_req_requester_status_idx');
            $table->index(['inventory_item_id', 'created_at'], 'inv_change_req_item_created_idx');
        });

        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('inventory_change_request_id')->nullable()->constrained('inventory_change_requests')->nullOnDelete();
            $table->string('movement_type', 32);
            $table->integer('quantity_change');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['inventory_item_id', 'created_at'], 'inv_stock_mov_item_created_idx');
            $table->index(['movement_type', 'created_at'], 'inv_stock_mov_type_created_idx');
            $table->index(['inventory_change_request_id', 'created_at'], 'inv_stock_mov_request_created_idx');
            $table->index(['performed_by', 'created_at'], 'inv_stock_mov_performer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
        Schema::dropIfExists('inventory_change_requests');
    }
};
