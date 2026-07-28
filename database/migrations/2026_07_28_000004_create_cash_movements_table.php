<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 30); // cashier_transfer or withdrawal
            $table->string('cash_drawer', 20)->default('room');
            $table->decimal('amount', 10, 2);
            $table->string('description', 500);
            $table->timestamp('moved_at');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['shift_session_id', 'cash_drawer', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
