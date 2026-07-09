<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\RoomType;
use App\Models\Room;
use App\Models\Booking;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\InventoryItem;
use App\Models\InventoryUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\ShiftController;

class SeparateCashDrawersTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_cash_calculation_and_proportional_split()
    {
        // 1. Create a user
        $user = User::create([
            'username' => 'cashier_test',
            'password' => bcrypt('password'),
            'full_name' => 'Test Cashier',
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // 2. Start a shift session
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        // 3. Create helper models (RoomType, Room)
        $roomType = RoomType::create([
            'type_name' => 'Deluxe Room',
            'base_rate' => 1500.00,
            'hourly_rate' => 300.00,
            'max_occupancy' => 2,
        ]);

        $room = Room::create([
            'room_number' => '101',
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        // 4. Create an inventory item for minibar
        $item = InventoryItem::create([
            'item_name' => 'Coke',
            'category' => 'minibar',
            'unit' => 'can',
            'current_stock' => 100,
            'unit_cost' => 20.00,
            'selling_price' => 50.00,
        ]);

        // 5. Create a booking that gets checked in
        $booking = Booking::create([
            'booking_ref' => 'REF001',
            'room_id' => $room->id,
            'guest_name' => 'Guest One',
            'check_in' => now()->subHours(2),
            'expected_check_out' => now()->addHours(2),
            'status' => 'active',
            'payment_status' => 'unpaid',
            'base_amount' => 1500.00,
            'total_amount' => 1500.00,
            'checked_in_by' => $user->id,
        ]);

        // 6. Log check_in cash transaction (goes to Rooms Drawer)
        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Check in deposit',
            'amount' => 1500.00,
            'payment_method' => 'cash',
            'cash_amount' => 1500.00,
            'processed_by' => $user->id,
        ]);

        // 7. Consume minibar item during stay
        $usage = InventoryUsage::create([
            'booking_id' => $booking->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 50.00,
            'total_price' => 100.00,
            'recorded_by' => $user->id,
        ]);

        // 8. Create a check_out transaction with split payment (cash + digital)
        // Checkout bill total: Minibar = 100.00, Rooms (Late checkout / extensions / extra pax, here we simulate additional check_out fee = 200.00)
        // Total additional checkout due: 300.00
        // We pay via split: Cash = 150.00, GCash = 150.00
        $checkoutTx = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_out',
            'description' => 'Checkout settlement',
            'amount' => 300.00,
            'payment_method' => 'split',
            'cash_amount' => 150.00,
            'gcash_amount' => 150.00,
            'processed_by' => $user->id,
        ]);

        // Link inventory usage to checkout transaction
        $usage->transaction_id = $checkoutTx->id;
        $usage->save();

        // 9. Create a direct minibar walk-in POS transaction (goes 100% to Minibar Drawer)
        Transaction::create([
            'transaction_type' => 'pos_sale',
            'description' => 'Direct POS Sale',
            'amount' => 200.00,
            'payment_method' => 'cash',
            'cash_amount' => 200.00,
            'processed_by' => $user->id,
        ]);

        // Now calculate expected drawer cash for running shift
        $shiftController = new ShiftController();
        $salesStats = $shiftController->getShiftSalesSummary($user->id, $shift->started_at, now());
        $expectedDrawerCash = $shift->opening_cash + $salesStats['rooms_cash'];
        $expectedDrawerCashMinibar = $shift->opening_cash_minibar + $salesStats['minibar_cash'];

        $liveSummary = [
            'sales' => $salesStats,
            'expected_drawer_cash' => $expectedDrawerCash,
            'expected_drawer_cash_minibar' => $expectedDrawerCashMinibar,
        ];

        // Verification:
        // Cash transactions in this shift:
        // A. check_in: 1500.00 cash (100% Rooms)
        // B. pos_sale: 200.00 cash (100% Minibar)
        // C. check_out: 150.00 cash (split payment)
        //    - Minibar billing in check_out = 100.00 (the inventory usage)
        //    - Rooms billing in check_out = 200.00 (300.00 total - 100.00 minibar)
        //    - Proportional allocation:
        //      - Minibar portion of cash = 150.00 * (100.00 / 300.00) = 50.00
        //      - Rooms portion of cash = 150.00 * (200.00 / 300.00) = 100.00
        //
        // Total expected Rooms cash = opening (1000.00) + check_in cash (1500.00) + checkout rooms cash (100.00) = 2600.00
        // Total expected Minibar cash = opening (500.00) + pos_sale cash (200.00) + checkout minibar cash (50.00) = 750.00

        $this->assertEquals(2600.00, $liveSummary['expected_drawer_cash']);
        $this->assertEquals(750.00, $liveSummary['expected_drawer_cash_minibar']);

        // Assert that the detailed cash collected sums match too
        $this->assertEquals(1600.00, $liveSummary['sales']['rooms_cash']);
        $this->assertEquals(250.00, $liveSummary['sales']['minibar_cash']);
    }

    public function test_optional_late_checkout_fee_waiving()
    {
        $user = User::create([
            'username' => 'cashier_test2',
            'password' => bcrypt('password'),
            'full_name' => 'Test Cashier 2',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        $roomType = RoomType::create([
            'type_name' => 'Deluxe Room 2',
            'base_rate' => 1500.00,
            'hourly_rate' => 300.00,
            'max_occupancy' => 2,
        ]);

        $room = Room::create([
            'room_number' => '102',
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        $booking = Booking::create([
            'booking_ref' => 'REF002',
            'room_id' => $room->id,
            'guest_name' => 'Guest Two',
            'check_in' => now()->subHours(5),
            'expected_check_out' => now()->subHours(2), // Overstayed by 2 hours
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1500.00,
            'total_amount' => 1500.00,
            'amount_paid' => 1500.00,
            'checked_in_by' => $user->id,
        ]);

        // Settle checkout with waive_late_fee = true
        $response = $this->actingAs($user)
            ->post(route('bookings.checkout', $booking->id), [
                'payment_method' => 'cash',
                'waive_late_fee' => true,
                'notes' => 'Waived late fee for VIP'
            ]);

        $response->assertRedirect();
        
        $booking->refresh();
        $this->assertEquals('checked_out', $booking->status);
        $this->assertEquals(0.00, $booking->late_checkout_fee); // Assert late fee is 0.00 (waived)
        $this->assertStringContainsString('Waived late fee for VIP', $booking->notes);
        $this->assertStringContainsString('waived by Test Cashier 2', $booking->notes);
    }

    public function test_shift_sales_summary_with_operational_incomes_and_expenses()
    {
        $user = User::create([
            'username' => 'cashier_ops_test',
            'password' => bcrypt('password'),
            'full_name' => 'Ops Cashier',
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        // Record income targeting Room drawer
        \App\Models\Income::create([
            'income_date' => now()->format('Y-m-d'),
            'amount' => 300.00,
            'cash_drawer' => 'room',
            'notes' => 'Float topup',
            'recorded_by' => $user->id
        ]);

        // Record expense targeting Minibar drawer
        \App\Models\Expense::create([
            'expense_date' => now()->format('Y-m-d'),
            'amount' => 100.00,
            'cash_drawer' => 'minibar',
            'notes' => 'Minibar supplies',
            'recorded_by' => $user->id
        ]);

        $shiftController = new ShiftController();
        $salesStats = $shiftController->getShiftSalesSummary($user->id, $shift->started_at, now());

        // rooms_cash should have +300.00
        // minibar_cash should have -100.00
        $this->assertEquals(300.00, $salesStats['rooms_cash']);
        $this->assertEquals(-100.00, $salesStats['minibar_cash']);
    }

    public function test_transaction_notes_storing()
    {
        $user = User::create([
            'username' => 'cashier_notes_test',
            'password' => bcrypt('password'),
            'full_name' => 'Notes Cashier',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        $roomType = RoomType::create([
            'type_name' => 'Deluxe Room Notes',
            'base_rate' => 1500.00,
            'hourly_rate' => 300.00,
            'max_occupancy' => 2,
        ]);

        $room = Room::create([
            'room_number' => '109',
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        // Check in room with transaction notes
        $response = $this->actingAs($user)->post(route('checkin.store'), [
            'room_ids' => [$room->id],
            'check_in' => now()->format('Y-m-d\TH:i'),
            'guest_name' => 'Guest Notes',
            'guest_contact' => '09123456789',
            'guest_id_type' => 'Driver License',
            'guest_id_number' => '123-456',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'discount_type' => 'none',
            'payment_method' => 'cash',
            'cash_amount' => 1500.00,
            'transaction_notes' => 'Tendered exact cash amount',
        ]);

        $response->assertRedirect();

        $booking = Booking::where('guest_name', 'Guest Notes')->first();
        $this->assertNotNull($booking);

        // Assert check-in transaction notes
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'notes' => 'Tendered exact cash amount',
        ]);

        // Extend booking with transaction notes
        $response2 = $this->actingAs($user)->post(route('bookings.extend', $booking->id), [
            'hours' => 3,
            'payment_method' => 'cash',
            'cash_amount' => 900.00,
            'transaction_notes' => 'Extended stay by 3 hours cash payment',
        ]);

        $response2->assertRedirect();

        // Assert extension transaction notes
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking->id,
            'transaction_type' => 'extension',
            'notes' => 'Extended stay by 3 hours cash payment',
        ]);

        // Checkout booking with notes
        $response3 = $this->actingAs($user)->post(route('bookings.checkout', $booking->id), [
            'payment_method' => 'cash',
            'cash_amount' => 0.00,
            'notes' => 'Checked out guest completely',
        ]);

        $response3->assertRedirect();

        // Assert checkout transaction notes
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking->id,
            'transaction_type' => 'check_out',
            'notes' => 'Checked out guest completely',
        ]);
    }
}
