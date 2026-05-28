<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. room_types
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name', 50);
            $table->text('description')->nullable();
            $table->decimal('base_rate', 10, 2)->default(0.00);
            $table->decimal('hourly_rate', 10, 2)->default(0.00);
            $table->decimal('short_time_3h_rate', 10, 2)->default(0.00);
            $table->decimal('short_time_6h_rate', 10, 2)->default(0.00);
            $table->decimal('short_time_12h_rate', 10, 2)->default(0.00);
            $table->decimal('short_time_24h_rate', 10, 2)->default(0.00);
            $table->integer('max_occupancy')->default(2);
            $table->text('amenities')->nullable(); // comma-separated or json string
            $table->timestamps();
        });

        // 2. rooms
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number', 10)->unique();
            $table->foreignId('room_type_id')->constrained('room_types')->onDelete('cascade');
            $table->integer('floor')->default(1);
            $table->enum('status', ['vacant', 'occupied', 'cleaning', 'out_of_order'])->default('vacant');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 3. peak_dates
        Schema::create('peak_dates', function (Blueprint $table) {
            $table->id();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('label', 100)->nullable();
            $table->decimal('surcharge_amount', 10, 2)->default(100.00);
            $table->enum('surcharge_type', ['fixed', 'percent'])->default('fixed');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 4. guest_profiles
        Schema::create('guest_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 100);
            $table->string('contact_number', 20)->nullable();
            $table->string('id_type', 50)->nullable();
            $table->string('id_number', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_vip')->default(false);
            $table->text('vip_notes')->nullable();
            $table->integer('total_stays')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0.00);
            $table->date('last_visit')->nullable();
            $table->timestamps();
        });

        // 5. bookings
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_ref', 20)->unique();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('guest_profile_id')->nullable()->constrained('guest_profiles')->onDelete('set null');
            $table->string('guest_name', 100);
            $table->string('guest_contact', 20)->nullable();
            $table->string('guest_id_type', 50)->nullable();
            $table->string('guest_id_number', 50)->nullable();
            $table->integer('num_guests')->default(1);
            $table->enum('booking_type', ['overnight', 'hourly', 'short_time'])->default('overnight');
            $table->integer('short_time_hours')->nullable();
            $table->dateTime('check_in');
            $table->dateTime('check_out')->nullable();
            $table->dateTime('expected_check_out')->nullable();
            $table->enum('status', ['active', 'checked_out', 'cancelled', 'no_show'])->default('active');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->decimal('base_amount', 10, 2)->default(0.00);
            $table->decimal('peak_surcharge', 10, 2)->default(0.00);
            $table->string('discount_type', 50)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('extension_fee', 10, 2)->default(0.00);
            $table->decimal('late_checkout_fee', 10, 2)->default(0.00);
            $table->integer('late_hours')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->decimal('amount_paid', 10, 2)->default(0.00);
            $table->enum('payment_method', ['cash', 'gcash', 'split'])->default('cash');
            $table->decimal('cash_amount', 10, 2)->default(0.00);
            $table->decimal('gcash_amount', 10, 2)->default(0.00);
            $table->string('gcash_ref', 50)->nullable();
            $table->boolean('is_peak')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 6. transactions
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->onDelete('cascade');
            $table->enum('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment', 'inventory'])->default('check_in');
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->enum('payment_method', ['cash', 'gcash', 'split', 'na'])->default('cash');
            $table->decimal('cash_amount', 10, 2)->default(0.00);
            $table->decimal('gcash_amount', 10, 2)->default(0.00);
            $table->string('gcash_ref', 50)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 7. audit_logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action', 100);
            $table->string('module', 50)->nullable();
            $table->integer('record_id')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        // 8. inventory_items
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_name', 100);
            $table->enum('category', ['minibar', 'toiletries', 'laundry', 'amenities', 'supplies'])->default('amenities');
            $table->string('unit', 20)->default('pcs');
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_stock')->default(5);
            $table->decimal('unit_cost', 10, 2)->default(0.00);
            $table->decimal('selling_price', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 9. inventory_usage
        Schema::create('inventory_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0.00);
            $table->decimal('total_price', 10, 2)->default(0.00);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // 10. shift_sessions
        Schema::create('shift_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('shift_code', ['morning', 'evening', 'night']);
            $table->time('scheduled_start')->nullable();
            $table->time('scheduled_end')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('opening_cash', 10, 2)->default(0.00);
            $table->decimal('closing_cash', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_sessions');
        Schema::dropIfExists('inventory_usage');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('guest_profiles');
        Schema::dropIfExists('peak_dates');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
