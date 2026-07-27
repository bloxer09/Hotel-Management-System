<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->dateTime('received_at')->index();
            $table->string('payer_name', 150);
            $table->string('payer_contact', 50)->nullable();
            $table->string('payment_method_code', 40)->index();
            $table->string('reference_number', 100)->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_type', 30)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->index();
            $table->foreignId('shift_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('original_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('legacy_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['payment_method_code', 'reference_number']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'booking_id']);
            $table->index(['booking_id', 'payment_id']);
        });

        Schema::create('payment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('payment_method_code', 40)->index();
            $table->decimal('amount', 12, 2);
            $table->string('reference_number', 100)->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('booking_id')->constrained('payments')->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('booked_by_user_id')->nullable()->after('guest_profile_id')->constrained('users')->nullOnDelete();
            $table->string('booker_name', 150)->nullable()->after('guest_name');
            $table->string('booker_contact', 50)->nullable()->after('guest_contact');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['booked_by_user_id']);
            $table->dropColumn(['booked_by_user_id', 'booker_name', 'booker_contact']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::dropIfExists('payment_components');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
