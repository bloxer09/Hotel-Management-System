<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingNightCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_overnight_booking_derives_nights_from_confirmed_stay_dates(): void
    {
        $booking = $this->createBooking([
            'booking_type' => 'overnight',
            'num_nights' => null,
            'check_in' => '2026-07-30 14:00:00',
            'expected_check_out' => '2026-08-01 12:00:00',
        ]);

        $this->assertSame(2, $booking->num_nights);
        $this->assertSame(2, $booking->fresh()->num_nights);
    }

    public function test_saved_night_count_remains_authoritative(): void
    {
        $booking = $this->createBooking([
            'booking_type' => 'overnight',
            'num_nights' => 4,
            'check_in' => '2026-07-30 14:00:00',
            'expected_check_out' => '2026-08-01 12:00:00',
        ]);

        $this->assertSame(4, $booking->num_nights);
    }

    public function test_short_time_booking_does_not_receive_an_overnight_count(): void
    {
        $booking = $this->createBooking([
            'booking_type' => 'short_time',
            'num_nights' => null,
            'short_time_hours' => 12,
            'check_in' => '2026-08-08 18:00:00',
            'expected_check_out' => '2026-08-09 06:00:00',
        ]);

        $this->assertNull($booking->num_nights);
    }

    private function createBooking(array $overrides): Booking
    {
        $roomType = RoomType::create([
            'type_name' => 'Night Count Room',
            'base_rate' => 900,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'NIGHT-'.$roomType->id,
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => 'RES-NIGHTS-'.$room->id,
            'room_id' => $room->id,
            'guest_name' => 'Night Count Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-07-30 14:00:00',
            'expected_check_out' => '2026-07-31 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'unpaid',
            'base_amount' => 900,
            'total_amount' => 900,
            'payment_method' => 'cash',
        ], $overrides));
    }
}
