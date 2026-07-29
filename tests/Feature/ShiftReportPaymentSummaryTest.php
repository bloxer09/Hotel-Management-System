<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\InventoryUsage;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftReportPaymentSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_ledger_separates_booking_total_paid_balance_and_shift_collections(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
        [$ledgerBooking, $legacyBooking] = $this->createBookings($user, true);

        app(PaymentService::class)->record([
            'payer_name' => $ledgerBooking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => 'GCASH-SHIFT-REPORT-001',
            'amount' => 400,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $user->id,
            'received_at' => now()->subMinutes(20),
        ], [
            $ledgerBooking->id => 400,
        ], [[
            'payment_method_code' => 'gcash',
            'amount' => 400,
            'reference_number' => 'GCASH-SHIFT-REPORT-001',
        ]]);

        Transaction::create([
            'booking_id' => $legacyBooking->id,
            'transaction_type' => 'check_in',
            'description' => 'Legacy reservation deposit',
            'amount' => 300,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'processed_by' => $user->id,
        ]);

        Transaction::create([
            'booking_id' => $legacyBooking->id,
            'transaction_type' => 'pos_sale',
            'description' => 'Room-billed minibar item',
            'amount' => 25,
            'payment_method' => 'cash',
            'cash_amount' => 25,
            'processed_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('shifts.report', $shift->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shifts/Report')
            ->has('report.bookings', 2)
            ->where('report.bookings.0.total_amount', 900)
            ->where('report.bookings.0.paid_amount', 300)
            ->where('report.bookings.0.balance_amount', 600)
            ->where('report.bookings.0.report_payment_status', 'partial')
            ->where('report.bookings.0.shift_collection_amount', 300)
            ->where('report.bookings.0.shift_collection_methods.cash', 300)
            ->where('report.bookings.1.total_amount', 1000)
            ->where('report.bookings.1.paid_amount', 400)
            ->where('report.bookings.1.balance_amount', 600)
            ->where('report.bookings.1.shift_collection_amount', 400)
            ->where('report.bookings.1.shift_collection_methods.gcash', 400)
            ->where('report.bookings.1.shift_collection_references.gcash.0', 'GCASH-SHIFT-REPORT-001')
            ->where('report.stay_collections.cash', 300)
            ->where('report.stay_collections.gcash', 400)
            ->where('report.stay_collections.total_received', 700)
            ->where('report.stay_collections.refunds', 0)
            ->where('report.stay_collections.net_collections', 700)
        );
    }

    public function test_pending_payment_is_shown_but_not_counted_as_a_collection(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);
        [$booking] = $this->createBookings($user, true);

        app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => 'GCASH-PENDING-REPORT-001',
            'amount' => 400,
            'payment_type' => 'deposit',
            'status' => 'pending',
            'recorded_by' => $user->id,
            'received_at' => now()->subMinutes(20),
        ], [
            $booking->id => 400,
        ], [[
            'payment_method_code' => 'gcash',
            'amount' => 400,
            'reference_number' => 'GCASH-PENDING-REPORT-001',
        ]]);

        $response = $this->actingAs($user)->get(route('shifts.report', $shift->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('report.bookings.1.paid_amount', 0)
            ->where('report.bookings.1.balance_amount', 1000)
            ->where('report.bookings.1.pending_payment_amount', 400)
            ->where('report.bookings.1.report_payment_status', 'pending_verification')
            ->where('report.stay_collections.total_received', 0)
        );
    }

    public function test_future_reservation_deposit_is_excluded_from_front_desk_room_sales(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);
        [$booking] = $this->createBookings($user, false);

        app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => 'FUTURE-DEPOSIT-EXCLUDED',
            'amount' => 400,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $user->id,
            'received_at' => now()->subMinutes(20),
        ], [$booking->id => 400], [[
            'payment_method_code' => 'gcash',
            'amount' => 400,
            'reference_number' => 'FUTURE-DEPOSIT-EXCLUDED',
        ]]);

        $response = $this->actingAs($user)->get(route('shifts.report', $shift->id));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('report.bookings', 0)
            ->where('report.stay_collections.total_received', 0)
        );
    }

    public function test_daily_cash_report_uses_room_cash_sales_and_excludes_minibar_pos(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'ended_at' => now()->addMinute(),
            'opening_cash' => 500,
            'closing_cash' => 600,
            'closing_denominations' => ['500' => 1, '100' => 1],
            'opening_cash_minibar' => 0,
            'closing_cash_minibar' => 0,
        ]);
        [, $booking] = $this->createBookings($user, true);

        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Cash room sale',
            'amount' => 300,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'processed_by' => $user->id,
        ]);
        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'pos_sale',
            'description' => 'Room minibar charge',
            'amount' => 25,
            'payment_method' => 'cash',
            'cash_amount' => 25,
            'processed_by' => $user->id,
        ]);
        Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 50,
            'cash_drawer' => 'room',
            'notes' => 'Room drawer supply expense',
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'cashier_transfer',
            'cash_drawer' => 'room',
            'amount' => 100,
            'description' => 'Cash remitted to cashier',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'withdrawal',
            'cash_drawer' => 'room',
            'amount' => 25,
            'description' => 'Approved withdrawal',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('shifts.report', $shift->id));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('report.daily_cash_report.room_sales_cash', 300)
            ->where('report.daily_cash_report.room_expenses', 50)
            ->where('report.daily_cash_report.cashier_transfers', 100)
            ->where('report.daily_cash_report.withdrawals', 25)
            ->where('report.daily_cash_report.expected_cash', 625)
            ->where('report.daily_cash_report.actual_cash', 600)
            ->where('report.daily_cash_report.variance', 25)
        );
    }

    public function test_official_logbook_includes_checkout_extra_charge_description_and_amount(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);
        [$booking] = $this->createBookings($user, true);

        $booking->update([
            'status' => 'checked_out',
            'check_out' => now()->subMinutes(5),
            'checked_out_by' => $user->id,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ]);

        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'adjustment',
            'description' => "Separate checkout charge (Extra Charges (Damaged towel) = P 500) for Ref: {$booking->booking_ref}",
            'amount' => 500,
            'payment_method' => 'gcash',
            'gcash_amount' => 500,
            'gcash_ref' => 'GCASH-DAMAGE-500',
            'processed_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/RoomBookingsLedgerPrint')
            ->where('bookings.1.shift_extra_charges.0.description', 'Damaged towel')
            ->where('bookings.1.shift_extra_charges.0.amount', 500)
            ->where('bookings.1.shift_extra_charges.0.payment_method', 'gcash')
            ->where('bookings.1.shift_extra_charges.0.payment_reference', 'GCASH-DAMAGE-500')
        );
    }

    public function test_separate_gcash_extra_charge_is_recorded_with_its_own_reference(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);
        [$booking] = $this->createBookings($user, true);

        $response = $this->actingAs($user)->post(route('bookings.checkout', $booking->id), [
            'payment_method' => 'cash',
            'extra_charge_amount' => 500,
            'extra_charge_description' => 'Damaged towel',
            'extra_charge_separate_payment' => true,
            'extra_charge_payment_method' => 'gcash',
            'extra_charge_payment_reference' => 'GCASH-SEPARATE-DAMAGE-500',
        ]);

        $response->assertRedirect(route('rooms.index'));
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking->id,
            'transaction_type' => 'adjustment',
            'amount' => 500,
            'payment_method' => 'gcash',
            'gcash_ref' => 'GCASH-SEPARATE-DAMAGE-500',
        ]);

        $response = $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('bookings.1.shift_extra_charges.0.description', 'Damaged towel')
            ->where('bookings.1.shift_extra_charges.0.payment_reference', 'GCASH-SEPARATE-DAMAGE-500')
        );
    }

    public function test_official_logbook_includes_minibar_tally_sales_and_low_stock_data(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'ended_at' => now()->addMinute(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 100,
            'closing_cash_minibar' => 120,
            'closing_denominations_minibar' => ['100' => 1, '20' => 1],
        ]);
        [$booking] = $this->createBookings($user, true);
        $item = InventoryItem::create([
            'item_name' => 'Mini Bar Water',
            'category' => 'minibar',
            'unit' => 'bottle',
            'current_stock' => 2,
            'minimum_stock' => 5,
            'unit_cost' => 10,
            'selling_price' => 25,
        ]);
        $sale = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'pos_sale',
            'description' => 'Mini bar water sale',
            'amount' => 50,
            'payment_method' => 'cash',
            'cash_amount' => 50,
            'processed_by' => $user->id,
        ]);
        InventoryUsage::create([
            'booking_id' => $booking->id,
            'transaction_id' => $sale->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 25,
            'total_price' => 50,
            'recorded_by' => $user->id,
        ]);
        Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 40,
            'cash_drawer' => 'minibar',
            'notes' => 'Mini bar supplies',
            'recorded_by' => $user->id,
        ]);
        \App\Models\Income::create([
            'income_date' => now()->toDateString(),
            'amount' => 30,
            'cash_drawer' => 'minibar',
            'notes' => 'Cash added to mini bar drawer',
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'cashier_transfer',
            'cash_drawer' => 'minibar',
            'amount' => 20,
            'description' => 'Mini bar cash remittance',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('minibar.pos_sales', 1)
            ->has('minibar.stay_charges', 1)
            ->has('minibar.low_stock', 1)
            ->where('minibar.cash_tally.sales_cash', 50)
            ->where('minibar.cash_tally.other_cash_receipts', 30)
            ->where('minibar.cash_tally.total_expenses', 40)
            ->where('minibar.cash_tally.total_movements', 20)
            ->where('minibar.cash_tally.expected_cash', 120)
            ->where('minibar.cash_tally.actual_cash', 120)
        );
    }

    public function test_cash_movement_can_be_recorded_against_the_minibar_drawer(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 100,
        ]);

        $response = $this->actingAs($user)->post(route('shifts.cash_movements.store', $shift->id), [
            'movement_type' => 'cashier_transfer',
            'cash_drawer' => 'minibar',
            'amount' => 50,
            'description' => 'Mini bar drawer remittance',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cash_movements', [
            'shift_session_id' => $shift->id,
            'cash_drawer' => 'minibar',
            'movement_type' => 'cashier_transfer',
            'amount' => 50,
        ]);
    }

    private function createCashier(): User
    {
        return User::create([
            'username' => 'shift_report_' . uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Shift Report Cashier',
            'role' => 'cashier',
            'is_active' => true,
        ]);
    }

    private function createBookings(User $user, bool $checkedInThisShift): array
    {
        $roomType = RoomType::create([
            'type_name' => 'Shift Report Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);

        $bookings = [];
        foreach ([1000, 900] as $index => $total) {
            $room = Room::create([
                'room_number' => 'SR-' . ($index + 1),
                'room_type_id' => $roomType->id,
                'status' => 'vacant',
            ]);
            $bookings[] = Booking::create([
                'booking_ref' => 'SR' . ($index + 1) . strtoupper(substr(uniqid(), -10)),
                'room_id' => $room->id,
                'booked_by_user_id' => $user->id,
                'guest_name' => 'Future Guest ' . ($index + 1),
                'guest_contact' => '0900000000' . $index,
                'num_guests' => 2,
                'booking_type' => 'overnight',
                'num_nights' => 1,
                'check_in' => $checkedInThisShift ? now()->subMinutes(10 + $index) : now()->addDays($index + 2),
                'expected_check_out' => $checkedInThisShift ? now()->addDay() : now()->addDays($index + 3),
                'status' => $checkedInThisShift ? 'active' : 'reserved',
                'payment_status' => $index === 0 ? 'unpaid' : 'partial',
                'base_amount' => $total,
                'total_amount' => $total,
                'amount_paid' => $index === 0 ? 0 : 300,
                'payment_method' => $index === 0 ? 'gcash' : 'cash',
                'cash_amount' => $index === 0 ? 0 : 300,
                'gcash_amount' => 0,
                'checked_in_by' => $checkedInThisShift ? $user->id : null,
            ]);
        }

        return $bookings;
    }
}
