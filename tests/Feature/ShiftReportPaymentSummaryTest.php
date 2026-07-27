<?php

namespace Tests\Feature;

use App\Models\Booking;
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
        [$ledgerBooking, $legacyBooking] = $this->createFutureBookings($user);

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

        $response = $this->actingAs($user)->get(route('shifts.report', $shift->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shifts/Report')
            ->has('report.bookings', 2)
            ->where('report.bookings.0.total_amount', 1000)
            ->where('report.bookings.0.paid_amount', 400)
            ->where('report.bookings.0.balance_amount', 600)
            ->where('report.bookings.0.report_payment_status', 'partial')
            ->where('report.bookings.0.shift_collection_amount', 400)
            ->where('report.bookings.0.shift_collection_methods.gcash', 400)
            ->where('report.bookings.1.total_amount', 900)
            ->where('report.bookings.1.paid_amount', 300)
            ->where('report.bookings.1.balance_amount', 600)
            ->where('report.bookings.1.shift_collection_amount', 300)
            ->where('report.bookings.1.shift_collection_methods.cash', 300)
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
        [$booking] = $this->createFutureBookings($user);

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
            ->where('report.bookings.0.paid_amount', 0)
            ->where('report.bookings.0.balance_amount', 1000)
            ->where('report.bookings.0.pending_payment_amount', 400)
            ->where('report.bookings.0.report_payment_status', 'pending_verification')
            ->where('report.stay_collections.total_received', 0)
        );
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

    private function createFutureBookings(User $user): array
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
                'check_in' => now()->addDays($index + 2),
                'expected_check_out' => now()->addDays($index + 3),
                'status' => 'reserved',
                'payment_status' => $index === 0 ? 'unpaid' : 'partial',
                'base_amount' => $total,
                'total_amount' => $total,
                'amount_paid' => $index === 0 ? 0 : 300,
                'payment_method' => $index === 0 ? 'gcash' : 'cash',
                'cash_amount' => $index === 0 ? 0 : 300,
                'gcash_amount' => 0,
                'checked_in_by' => $user->id,
            ]);
        }

        return $bookings;
    }
}
