<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationBookingPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_walk_in_cash_booking_records_tendered_cash_and_change(): void
    {
        [$user, $room] = $this->createBookingContext();

        $response = $this->actingAs($user)->post(route('reservations.store'), [
            'room_ids' => [$room->id],
            'check_in' => '2026-08-27 14:00:00',
            'guest_name' => 'Walk In Guest',
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'payment_ratio' => 'full',
            'payment_method' => 'cash',
            'cash_received' => 2000,
        ]);

        $response->assertRedirect(route('reservations.index'));

        $booking = Booking::where('guest_name', 'Walk In Guest')->firstOrFail();
        $payment = Payment::whereHas(
            'allocations',
            fn ($query) => $query->where('booking_id', $booking->id)
        )->firstOrFail();

        $this->assertSame('walk_in', $booking->booking_source);
        $this->assertSame('verified', $payment->status);
        $this->assertSame(2000.0, $payment->cash_tendered);
        $this->assertSame(round(2000 - $payment->amount, 2), $payment->change_given);
    }

    public function test_online_booking_requires_a_digital_method_and_saves_reference(): void
    {
        [$user, $room] = $this->createBookingContext();

        $response = $this->actingAs($user)->post(route('reservations.store'), [
            'room_ids' => [$room->id],
            'check_in' => '2026-08-28 14:00:00',
            'guest_name' => 'Online Guest',
            'booking_type' => 'overnight',
            'booking_source' => 'online',
            'num_nights' => 1,
            'payment_ratio' => 'full',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-ONLINE-001',
        ]);

        $response->assertRedirect(route('reservations.index'));

        $booking = Booking::where('guest_name', 'Online Guest')->firstOrFail();
        $payment = Payment::whereHas(
            'allocations',
            fn ($query) => $query->where('booking_id', $booking->id)
        )->firstOrFail();

        $this->assertSame('online', $booking->booking_source);
        $this->assertSame('bank_transfer', $payment->payment_method_code);
        $this->assertSame('BANK-ONLINE-001', $payment->reference_number);
        $this->assertSame('pending', $payment->status);
    }

    public function test_online_booking_rejects_cash_payment(): void
    {
        [$user, $room] = $this->createBookingContext();

        $response = $this->actingAs($user)->from(route('reservations.index'))
            ->post(route('reservations.store'), [
                'room_ids' => [$room->id],
                'check_in' => '2026-08-29 14:00:00',
                'guest_name' => 'Invalid Online Guest',
                'booking_type' => 'overnight',
                'booking_source' => 'online',
                'num_nights' => 1,
                'payment_ratio' => 'full',
                'payment_method' => 'cash',
                'cash_received' => 2000,
            ]);

        $response->assertRedirect(route('reservations.index'));
        $response->assertSessionHasErrors('payment_method');
        $this->assertDatabaseMissing('bookings', ['guest_name' => 'Invalid Online Guest']);
    }

    private function createBookingContext(): array
    {
        $user = User::create([
            'username' => 'booking_payment_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Booking Cashier',
            'role' => 'front_desk',
            'is_active' => true,
        ]);

        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);

        $roomType = RoomType::create([
            'type_name' => 'Booking Flow Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);

        $room = Room::create([
            'room_number' => 'BF-'.substr(uniqid(), -6),
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        return [$user, $room];
    }
}
