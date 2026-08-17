<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationArrivalSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $frontDesk;
    private ShiftSession $shift;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 18:00:00', HotelDateTime::TIMEZONE));

        $this->frontDesk = User::create([
            'username' => 'arrival_front_desk',
            'password' => bcrypt('password'),
            'full_name' => 'Arrival Front Desk',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
        $this->shift = ShiftSession::create([
            'user_id' => $this->frontDesk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
        $type = RoomType::create([
            'type_name' => 'Arrival Room',
            // Deliberately different from the confirmed booking price. Check-in
            // must preserve the contract instead of repricing at the live rate.
            'base_rate' => 2500,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'ARR-1',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cash_balance_is_recorded_once_in_current_shift_and_guest_is_checked_in_atomically(): void
    {
        $booking = $this->partiallyPaidBooking();

        $response = $this->actingAs($this->frontDesk)->post(
            route('reservations.settle_checkin', $booking),
            [
                'payer_name' => 'Arrival Payer',
                'payer_contact' => '09170000000',
                'payment_method_code' => 'cash',
                'remarks' => 'Balance paid at arrival',
            ]
        );

        $response->assertRedirect(route('rooms.index'));

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(1800.0, $booking->total_amount);
        $this->assertSame(1800.0, $booking->amount_paid);
        $this->assertSame($this->frontDesk->id, $booking->checked_in_by);
        $this->assertSame('occupied', $this->room->fresh()->status);

        $finalPayment = Payment::where('payment_type', 'final')->firstOrFail();
        $this->assertSame(900.0, $finalPayment->amount);
        $this->assertSame('verified', $finalPayment->status);
        $this->assertSame($this->frontDesk->id, $finalPayment->recorded_by);
        $this->assertSame($this->shift->id, $finalPayment->shift_id);
        $this->assertDatabaseCount('payments', 2);

        $frontDeskReport = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $frontDeskProps = $frontDeskReport->viewData('page')['props'];
        $this->assertEquals(900.0, $frontDeskProps['summary']['gross']);
        $this->assertSame($finalPayment->receipt_number, $frontDeskProps['collections'][0]['receipt_number']);

        $shiftReport = $this->actingAs($this->frontDesk)->get(route('shifts.report', $this->shift));
        $shiftProps = $shiftReport->viewData('page')['props'];
        $this->assertEquals(900.0, $shiftProps['report']['stay_collections']['cash']);
        $this->assertEquals(900.0, $shiftProps['report']['stay_collections']['total_received']);
    }

    public function test_digital_balance_stays_pending_and_duplicate_settlement_is_blocked_until_verification(): void
    {
        $booking = $this->partiallyPaidBooking();

        $response = $this->actingAs($this->frontDesk)
            ->from(route('reservations.index'))
            ->post(route('reservations.settle_checkin', $booking), [
                'payer_name' => 'Arrival GCash Payer',
                'payment_method_code' => 'gcash',
                'reference_number' => 'ARRIVAL-GCASH-001',
            ]);

        $response->assertRedirect(route('reservations.index'));
        $booking->refresh();
        $this->assertSame('reserved', $booking->status);
        $this->assertSame(900.0, $booking->amount_paid);
        $this->assertSame('vacant', $this->room->fresh()->status);

        $pending = Payment::where('payment_type', 'final')->firstOrFail();
        $this->assertSame(900.0, $pending->amount);
        $this->assertSame('pending', $pending->status);
        $this->assertSame($this->shift->id, $pending->shift_id);

        $reservations = $this->actingAs($this->frontDesk)->get(route('reservations.index'));
        $reservationProps = $reservations->viewData('page')['props'];
        $this->assertEquals(900.0, $reservationProps['reservations']['data'][0]['pending_payment_amount']);

        $duplicate = $this->actingAs($this->frontDesk)
            ->from(route('reservations.index'))
            ->post(route('reservations.settle_checkin', $booking), [
                'payer_name' => 'Duplicate Payer',
                'payment_method_code' => 'gcash',
                'reference_number' => 'ARRIVAL-GCASH-002',
            ]);
        $duplicate->assertRedirect(route('reservations.index'));
        $duplicate->assertSessionHas('error');
        $this->assertDatabaseCount('payments', 2);

        app(PaymentService::class)->verify($pending, $this->frontDesk->id);
        $booking->refresh();
        $this->assertSame(1800.0, $booking->amount_paid);

        $checkIn = $this->actingAs($this->frontDesk)->post(route('reservations.checkin', $booking));
        $checkIn->assertRedirect(route('rooms.index'));
        $this->assertSame('active', $booking->fresh()->status);
        $this->assertSame(1800.0, $booking->fresh()->total_amount);
    }

    public function test_arrival_settlement_requires_the_recording_staff_to_have_an_active_shift(): void
    {
        $booking = $this->partiallyPaidBooking();
        $this->shift->update(['ended_at' => now()]);

        $response = $this->actingAs($this->frontDesk)
            ->from(route('reservations.index'))
            ->post(route('reservations.settle_checkin', $booking), [
                'payer_name' => 'Arrival Payer',
                'payment_method_code' => 'cash',
            ]);

        $response->assertRedirect(route('shifts.index'));
        $response->assertSessionHas('warning');
        $this->assertSame('reserved', $booking->fresh()->status);
        $this->assertSame(900.0, $booking->fresh()->amount_paid);
        $this->assertDatabaseCount('payments', 1);
    }

    private function partiallyPaidBooking(): Booking
    {
        $booking = Booking::create([
            'booking_ref' => 'ARR'.strtoupper(substr(uniqid(), -10)),
            'room_id' => $this->room->id,
            'booked_by_user_id' => $this->frontDesk->id,
            'guest_name' => 'Arrival Guest',
            'booker_name' => 'Advance Booker',
            'guest_contact' => '09171111111',
            'booker_contact' => '09172222222',
            'check_in' => now()->addHour(),
            'expected_check_out' => now()->addDay(),
            'status' => 'reserved',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'unpaid',
            'base_amount' => 1800,
            'total_amount' => 1800,
            'amount_paid' => 0,
            'payment_method' => 'cash',
        ]);

        app(PaymentService::class)->record([
            'received_at' => now()->subDay(),
            'payer_name' => 'Advance Booker',
            'payment_method_code' => 'cash',
            'amount' => 900,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $this->frontDesk->id,
        ], [$booking->id => 900]);

        return $booking->fresh();
    }
}
