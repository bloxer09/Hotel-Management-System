<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ShiftCashReconciliationService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentVerificationUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $frontDesk;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 15:00:00', HotelDateTime::TIMEZONE));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'full_name' => 'Verify Admin',
        ]);
        $this->frontDesk = User::factory()->create([
            'role' => 'front_desk',
            'full_name' => 'Verify Front Desk',
        ]);
        ShiftSession::create([
            'user_id' => $this->frontDesk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 5000,
            'opening_cash_minibar' => 0,
        ]);
        $type = RoomType::create([
            'type_name' => 'Verify Room',
            'base_rate' => 1500,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'VR-1',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gcash_reservation_deposit_stays_pending_and_appears_in_queue(): void
    {
        $booking = $this->reservedBooking(1500);
        $payment = $this->recordPendingGcash($booking, 375, 'GCASH-UX-375');

        $this->assertSame('pending', $payment->status);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);

        $queue = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $props = $queue->viewData('page')['props'];
        $this->assertCount(0, $props['collections']);
        $this->assertSame($payment->receipt_number, $props['pendingPayments'][0]['receipt_number']);
        $this->assertSame($booking->id, $props['pendingPayments'][0]['first_booking_id']);
        $this->assertEquals(375.0, $props['pendingPayments'][0]['amount']);
    }

    public function test_verify_from_booking_show_matches_queue_verification_accounting(): void
    {
        $booking = $this->reservedBooking(1500);
        $payment = $this->recordPendingGcash($booking, 750, 'GCASH-BOOKING-SHOW');

        $show = $this->actingAs($this->frontDesk)->get(route('bookings.show', $booking));
        $show->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookings/Show')
            ->where('booking.pending_payment_amount', 750)
            ->where('booking.outstanding_verified_balance', 1500)
            ->where('booking.payments.0.status', 'pending')
            ->where('booking.payments.0.reference_number', 'GCASH-BOOKING-SHOW')
        );

        $this->actingAs($this->frontDesk)
            ->from(route('bookings.show', $booking))
            ->post(route('payments.verify', $payment), [
                'reference_number' => 'GCASH-BOOKING-SHOW',
            ])
            ->assertRedirect();

        $booking->refresh();
        $payment->refresh();
        $this->assertSame('verified', $payment->status);
        $this->assertSame($this->frontDesk->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
        $this->assertEquals(750.0, $booking->amount_paid);
        $this->assertEquals(750.0, $booking->outstanding_verified_balance ?? round($booking->total_amount - $booking->amount_paid, 2));
        $this->assertDatabaseHas('transactions', [
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'amount' => 750,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYMENT_VERIFIED',
            'record_id' => $payment->id,
            'user_id' => $this->frontDesk->id,
        ]);

        $queue = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $props = $queue->viewData('page')['props'];
        $this->assertCount(0, $props['pendingPayments']);
        $this->assertSame($payment->receipt_number, $props['collections'][0]['receipt_number']);
    }

    public function test_verify_from_stay_details_json_flow_syncs_booking_and_audit(): void
    {
        $booking = $this->activeStay(1500);
        $payment = $this->recordPendingGcash($booking, 375, 'GCASH-STAY-JSON');

        $json = $this->actingAs($this->frontDesk)
            ->get(route('bookings.show', ['booking' => $booking->id, 'json' => 1]))
            ->assertOk()
            ->json();
        $this->assertEquals(375.0, $json['booking']['pending_payment_amount']);
        $this->assertEquals(0.0, $json['booking']['amount_paid']);
        $this->assertSame('pending', $json['booking']['payments'][0]['status']);

        $this->actingAs($this->frontDesk)
            ->from(route('bookings.show', $booking))
            ->post(route('payments.verify', $payment), [
                'reference_number' => 'GCASH-STAY-JSON',
            ])
            ->assertRedirect();

        $refreshed = $this->actingAs($this->frontDesk)
            ->get(route('bookings.show', ['booking' => $booking->id, 'json' => 1]))
            ->json();
        $this->assertEquals(0.0, $refreshed['booking']['pending_payment_amount']);
        $this->assertEquals(375.0, $refreshed['booking']['amount_paid']);
        $this->assertSame('verified', $refreshed['booking']['payments'][0]['status']);
        $this->assertNotNull($refreshed['booking']['payments'][0]['verified_at']);
        $this->assertDatabaseHas('transactions', ['payment_id' => $payment->id]);
        $this->assertTrue(AuditLog::where('action', 'PAYMENT_VERIFIED')->where('record_id', $payment->id)->exists());
    }

    public function test_reject_from_booking_leaves_balance_unchanged_and_clears_queue(): void
    {
        $booking = $this->reservedBooking(1500);
        $payment = $this->recordPendingGcash($booking, 375, 'GCASH-REJECT-375');

        $this->actingAs($this->admin)
            ->from(route('bookings.show', $booking))
            ->post(route('payments.reject', $payment), [
                'reason' => 'Reference does not match GCash inbox',
            ])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame('rejected', $payment->status);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);
        $this->assertDatabaseMissing('transactions', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYMENT_REJECTED',
            'record_id' => $payment->id,
            'user_id' => $this->admin->id,
        ]);

        $queue = $this->actingAs($this->admin)->get(route('reports.front_desk'));
        $this->assertCount(0, $queue->viewData('page')['props']['pendingPayments']);
        $this->assertCount(0, $queue->viewData('page')['props']['collections']);
    }

    public function test_pending_digital_is_excluded_from_collections_and_shift_cash_until_verified(): void
    {
        $booking = $this->reservedBooking(1700);
        $payment = $this->recordPendingGcash($booking, 200, 'GCASH-SHIFT-EXCLUDE');
        $shift = ShiftSession::active()->firstOrFail();

        $before = app(ShiftCashReconciliationService::class)->forShift($shift);
        $this->assertSame(0.0, $before['rooms']['cash_collections']);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);

        $this->actingAs($this->frontDesk)->post(route('payments.verify', $payment), [
            'reference_number' => 'GCASH-SHIFT-EXCLUDE',
        ]);

        $after = app(ShiftCashReconciliationService::class)->forShift($shift->fresh());
        $this->assertSame(0.0, $after['rooms']['cash_collections']);
        $this->assertEquals(200.0, $booking->fresh()->amount_paid);

        $queue = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $props = $queue->viewData('page')['props'];
        $this->assertSame($payment->fresh()->receipt_number, $props['collections'][0]['receipt_number']);
        $this->assertEquals(200.0, $props['summary']['electronic_collections']);
    }

    public function test_booking_and_queue_verify_produce_identical_final_state(): void
    {
        $first = $this->reservedBooking(1000);
        $second = $this->reservedBooking(1000, 'VR-2');
        $fromBooking = $this->recordPendingGcash($first, 400, 'GCASH-PARITY-A');
        $fromQueue = $this->recordPendingGcash($second, 400, 'GCASH-PARITY-B');

        $this->actingAs($this->frontDesk)->post(route('payments.verify', $fromBooking), [
            'reference_number' => 'GCASH-PARITY-A',
        ]);
        $this->actingAs($this->frontDesk)->post(route('payments.verify', $fromQueue), [
            'reference_number' => 'GCASH-PARITY-B',
        ]);

        $fromBooking->refresh();
        $fromQueue->refresh();
        $this->assertSame($fromBooking->status, $fromQueue->status);
        $this->assertSame($fromBooking->verified_by, $fromQueue->verified_by);
        $this->assertEquals($first->fresh()->amount_paid, $second->fresh()->amount_paid);
        $this->assertEquals(
            Transaction::where('payment_id', $fromBooking->id)->sum('amount'),
            Transaction::where('payment_id', $fromQueue->id)->sum('amount')
        );
    }

    public function test_missing_required_reference_cannot_verify(): void
    {
        $booking = $this->reservedBooking(900);
        $payment = app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => null,
            'amount' => 200,
            'payment_type' => 'deposit',
            'status' => 'pending',
            'recorded_by' => $this->frontDesk->id,
        ], [$booking->id => 200]);

        $this->actingAs($this->frontDesk)
            ->from(route('bookings.show', $booking))
            ->post(route('payments.verify', $payment))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);
    }

    public function test_admin_and_front_desk_can_verify_and_housekeeping_cannot(): void
    {
        $booking = $this->reservedBooking(500);
        $adminPayment = $this->recordPendingGcash($booking, 100, 'GCASH-ADMIN-OK');
        $this->actingAs($this->admin)->post(route('payments.verify', $adminPayment), [
            'reference_number' => 'GCASH-ADMIN-OK',
        ])->assertRedirect();
        $this->assertSame('verified', $adminPayment->fresh()->status);

        $fdPayment = $this->recordPendingGcash($booking, 100, 'GCASH-FD-OK');
        $this->actingAs($this->frontDesk)->post(route('payments.verify', $fdPayment), [
            'reference_number' => 'GCASH-FD-OK',
        ])->assertRedirect();
        $this->assertSame('verified', $fdPayment->fresh()->status);

        $blocked = $this->recordPendingGcash($booking, 100, 'GCASH-HK-BLOCK');
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->post(route('payments.verify', $blocked), [
            'reference_number' => 'GCASH-HK-BLOCK',
        ])->assertForbidden();
        $this->actingAs($housekeeper)->post(route('payments.reject', $blocked), [
            'reason' => 'Should not work',
        ])->assertForbidden();
        $this->actingAs($housekeeper)->get(route('reports.front_desk'))->assertForbidden();
        $this->assertSame('pending', $blocked->fresh()->status);
    }

    public function test_check_in_blocked_until_in_context_verification(): void
    {
        $booking = $this->reservedBooking(1500);
        $payment = $this->recordPendingGcash($booking, 1500, 'GCASH-CHECKIN-GATE');

        $this->actingAs($this->frontDesk)
            ->from(route('reservations.index'))
            ->post(route('reservations.checkin', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame('reserved', $booking->fresh()->status);

        $this->actingAs($this->frontDesk)->post(route('payments.verify', $payment), [
            'reference_number' => 'GCASH-CHECKIN-GATE',
        ]);

        $this->actingAs($this->frontDesk)
            ->post(route('reservations.checkin', $booking))
            ->assertRedirect(route('rooms.index'));
        $this->assertSame('active', $booking->fresh()->status);
        $this->assertEquals(1500.0, $booking->fresh()->amount_paid);
    }

    public function test_walk_in_digital_check_in_remains_auto_verified(): void
    {
        $this->actingAs($this->frontDesk)->post(route('checkin.store'), [
            'room_ids' => [$this->room->id],
            'guest_name' => 'Walk-In GCash Guest',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'discount_type' => 'none',
            'payment_method' => 'gcash',
            'gcash_ref' => 'WALKIN-GCASH-AUTO',
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Walk-In GCash Guest')->firstOrFail();
        $payment = Payment::whereHas('allocations', fn ($q) => $q->where('booking_id', $booking->id))->firstOrFail();
        $this->assertSame('verified', $payment->status);
        $this->assertEquals((float) $booking->total_amount, $booking->amount_paid);
        $this->assertSame('active', $booking->status);

        $queue = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $this->assertFalse(collect($queue->viewData('page')['props']['pendingPayments'])
            ->contains('receipt_number', $payment->receipt_number));
    }

    public function test_checkout_digital_settlement_remains_auto_verified(): void
    {
        $booking = $this->activeStay(1000);
        app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'cash',
            'amount' => 1000,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->frontDesk->id,
        ], [$booking->id => 1000]);

        $this->actingAs($this->frontDesk)->post(route('bookings.checkout', $booking), [
            'payment_method' => 'gcash',
            'gcash_ref' => 'CHECKOUT-GCASH-AUTO',
            'extra_charge_amount' => 150,
            'extra_charge_description' => 'Late key card',
        ])->assertRedirect(route('rooms.index'));

        $settlement = Payment::where('reference_number', 'CHECKOUT-GCASH-AUTO')->firstOrFail();
        $this->assertSame('verified', $settlement->status);
        $this->assertSame('checked_out', $booking->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'payment_id' => $settlement->id,
            'amount' => 150,
        ]);
    }

    public function test_split_payment_stays_pending_as_one_record_including_cash(): void
    {
        $booking = $this->reservedBooking(1000);
        $payment = app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'split',
            'reference_number' => 'SPLIT-PENDING-UX',
            'amount' => 600,
            'payment_type' => 'partial',
            'status' => 'pending',
            'recorded_by' => $this->frontDesk->id,
        ], [$booking->id => 600], [
            ['payment_method_code' => 'cash', 'amount' => 200],
            ['payment_method_code' => 'gcash', 'amount' => 400, 'reference_number' => 'SPLIT-PENDING-UX'],
        ]);

        $shift = ShiftSession::active()->firstOrFail();
        $before = app(ShiftCashReconciliationService::class)->forShift($shift);
        $this->assertSame(0.0, $before['rooms']['cash_collections']);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);
        $this->assertSame('pending', $payment->status);

        $queue = $this->actingAs($this->frontDesk)->get(route('reports.front_desk'));
        $this->assertSame($payment->receipt_number, $queue->viewData('page')['props']['pendingPayments'][0]['receipt_number']);
        $this->assertSame('split', $queue->viewData('page')['props']['pendingPayments'][0]['payment_method']);
    }

    private function reservedBooking(float $total, string $roomNumber = 'VR-1'): Booking
    {
        $room = $this->room;
        if ($roomNumber !== $this->room->room_number) {
            $room = Room::create([
                'room_number' => $roomNumber,
                'room_type_id' => $this->room->room_type_id,
                'status' => 'vacant',
            ]);
        }

        return Booking::create([
            'booking_ref' => 'RES-UX'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'booked_by_user_id' => $this->frontDesk->id,
            'guest_name' => 'UX Guest',
            'booker_name' => 'UX Booker',
            'check_in' => now()->addDay(),
            'expected_check_out' => now()->addDays(2),
            'status' => 'reserved',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'unpaid',
            'base_amount' => $total,
            'total_amount' => $total,
            'amount_paid' => 0,
            'payment_method' => 'gcash',
        ]);
    }

    private function activeStay(float $total): Booking
    {
        $this->room->update(['status' => 'occupied']);

        return Booking::create([
            'booking_ref' => 'BKG-UX'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $this->room->id,
            'booked_by_user_id' => $this->frontDesk->id,
            'guest_name' => 'Stay UX Guest',
            'check_in' => HotelDateTime::now()->subHour()->format('Y-m-d H:i:s'),
            'expected_check_out' => HotelDateTime::now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'unpaid',
            'base_amount' => $total,
            'total_amount' => $total,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'checked_in_by' => $this->frontDesk->id,
        ]);
    }

    private function recordPendingGcash(Booking $booking, float $amount, string $reference): Payment
    {
        return app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => $reference,
            'amount' => $amount,
            'payment_type' => 'deposit',
            'status' => 'pending',
            'recorded_by' => $this->frontDesk->id,
        ], [$booking->id => $amount]);
    }
}
