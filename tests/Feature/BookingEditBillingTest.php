<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingEditBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $frontDesk;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frontDesk = User::create([
            'username' => 'booking_editor',
            'password' => bcrypt('password'),
            'full_name' => 'Booking Editor',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
        ShiftSession::create([
            'user_id' => $this->frontDesk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
        $type = RoomType::create([
            'type_name' => 'Editable Overnight',
            'base_rate' => 900,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'EDIT-1',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    public function test_changing_nights_recalculates_bill_and_preserves_ledger_payment(): void
    {
        $booking = $this->booking([
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
        ]);
        $payment = app(PaymentService::class)->record([
            'payer_name' => 'Billing Guest',
            'payment_method_code' => 'gcash',
            'reference_number' => 'EDIT-NIGHTS-GCASH-001',
            'amount' => 900,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->frontDesk->id,
            'received_at' => now(),
        ], [
            $booking->id => 900,
        ], [[
            'payment_method_code' => 'gcash',
            'amount' => 900,
            'reference_number' => 'EDIT-NIGHTS-GCASH-001',
        ]]);

        $response = $this->actingAs($this->frontDesk)
            ->put(route('bookings.update', $booking), $this->editPayload(3));

        $response->assertRedirect(route('reservations.index'));

        $booking->refresh();
        $this->assertSame(3, $booking->num_nights);
        $this->assertSame(2700.0, $booking->base_amount);
        $this->assertSame(2700.0, $booking->total_amount);
        $this->assertSame(900.0, $booking->amount_paid);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertSame('2026-08-02 12:00:00', $booking->expected_check_out->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 900,
            'status' => 'verified',
        ]);
    }

    public function test_legacy_paid_amount_is_preserved_but_status_tracks_new_total(): void
    {
        $booking = $this->booking([
            'amount_paid' => 900,
            'payment_status' => 'paid',
        ]);

        $response = $this->actingAs($this->frontDesk)
            ->put(route('bookings.update', $booking), $this->editPayload(2));

        $response->assertRedirect(route('reservations.index'));

        $booking->refresh();
        $this->assertSame(2, $booking->num_nights);
        $this->assertSame(1800.0, $booking->total_amount);
        $this->assertSame(900.0, $booking->amount_paid);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertDatabaseCount('payments', 0);
    }

    private function booking(array $overrides): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'RES-EDIT-BILLING',
            'room_id' => $this->room->id,
            'guest_name' => 'Billing Guest',
            'guest_contact' => '09170000000',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-07-30 14:00:00',
            'expected_check_out' => '2026-07-31 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'unpaid',
            'base_amount' => 900,
            'total_amount' => 900,
            'amount_paid' => 0,
            'payment_method' => 'gcash',
        ], $overrides));
    }

    private function editPayload(int $nights): array
    {
        return [
            'room_id' => $this->room->id,
            'check_in' => '2026-07-30 14:00:00',
            'guest_name' => 'Billing Guest',
            'guest_contact' => '09170000000',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => $nights,
            'short_time_hours' => 3,
            'discount_type' => 'none',
            'discount_amount' => 0,
            'notes' => 'Edited stay length',
        ];
    }
}
