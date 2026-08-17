<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationRoomAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_occupied_room_is_available_for_a_future_stay_after_current_guest_leaves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('occupied');
        $this->createStay($room, [
            'guest_name' => 'Current Guest',
            'status' => 'active',
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);

        $ids = $this->availableRoomIds($user, '2026-08-21 14:00:00');

        $this->assertContains($room->id, $ids);
    }

    public function test_cleaning_room_with_no_future_conflict_is_available_for_a_future_stay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('cleaning');

        $ids = $this->availableRoomIds($user, '2026-08-21 14:00:00');

        $this->assertContains($room->id, $ids);
    }

    public function test_overlapping_reservation_makes_the_room_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('vacant');
        $this->createStay($room, [
            'guest_name' => 'Future Guest',
            'status' => 'reserved',
            'check_in' => '2026-08-21 14:00:00',
            'expected_check_out' => '2026-08-22 12:00:00',
        ]);

        $ids = $this->availableRoomIds($user, '2026-08-21 14:00:00');

        $this->assertNotContains($room->id, $ids);
    }

    public function test_room_occupied_through_requested_check_in_is_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('occupied');
        $this->createStay($room, [
            'guest_name' => 'Stay Through Guest',
            'status' => 'active',
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-22 12:00:00',
        ]);

        $ids = $this->availableRoomIds($user, '2026-08-21 14:00:00');

        $this->assertNotContains($room->id, $ids);
    }

    public function test_same_day_reservation_excludes_a_cleaning_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('cleaning');

        $ids = $this->availableRoomIds($user, HotelDateTime::now()->format('Y-m-d H:i:s'), 2);

        $this->assertNotContains($room->id, $ids);
    }

    public function test_immediate_checkin_excludes_occupied_and_cleaning_rooms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $occupied = $this->createRoom('occupied');
        $cleaning = $this->createRoom('cleaning');
        $ready = $this->createRoom('vacant');

        $response = $this->actingAs($user)->postJson(route('reservations.available_rooms'), [
            'check_in' => HotelDateTime::now()->format('Y-m-d\TH:i'),
            'booking_type' => 'overnight',
            'num_nights' => 2,
            'purpose' => 'checkin',
        ])->assertOk();

        $ids = collect($response->json('available_rooms'))->pluck('id')->all();

        $this->assertNotContains($occupied->id, $ids);
        $this->assertNotContains($cleaning->id, $ids);
        $this->assertContains($ready->id, $ids);
        $this->assertTrue($response->json('require_physical_ready'));
    }

    public function test_vacant_room_without_overlap_remains_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));
        $user = $this->createAdmin();
        $room = $this->createRoom('vacant');

        $ids = $this->availableRoomIds($user, '2026-08-21 14:00:00');

        $this->assertContains($room->id, $ids);
    }

    private function availableRoomIds(User $user, string $checkIn, int $numNights = 1): array
    {
        $response = $this->actingAs($user)->postJson(route('reservations.available_rooms'), [
            'check_in' => $checkIn,
            'booking_type' => 'overnight',
            'num_nights' => $numNights,
            'purpose' => 'reservation',
        ])->assertOk();

        return collect($response->json('available_rooms'))->pluck('id')->all();
    }

    private function createAdmin(): User
    {
        return User::create([
            'username' => 'avail_admin_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Availability Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function createRoom(string $status): Room
    {
        $type = RoomType::create([
            'type_name' => 'Avail '.$status.' '.uniqid(),
            'base_rate' => 1500,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);

        return Room::create([
            'room_number' => 'AV-'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => $status,
        ]);
    }

    private function createStay(Room $room, array $overrides): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'BKG-'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'guest_name' => 'Stay Guest',
            'guest_contact' => '09170000000',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ], $overrides));
    }
}
