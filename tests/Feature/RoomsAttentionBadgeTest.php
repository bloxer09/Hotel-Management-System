<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomsAttentionBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-18 15:00:00', HotelDateTime::TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_eight_vacant_ready_rooms_show_zero_rooms_badge(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->makeRoom(['status' => 'vacant', 'room_number' => str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $this->assertSame(0, $this->roomsAttention($role));
        }
    }

    public function test_normally_occupied_rooms_outside_warning_window_are_not_counted(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->activeStay([
                'expected_check_out' => '2026-08-18 20:00:00',
            ]);
        }

        $this->assertSame(0, $this->roomsAttention('front_desk'));
        $this->assertSame(0, $this->roomsAttention('admin'));
        $this->assertSame(0, $this->roomsAttention('housekeeping'));
    }

    public function test_checkout_due_in_22_minutes_counts_one_room(): void
    {
        $this->activeStay(['expected_check_out' => '2026-08-18 15:22:00']);

        $this->assertSame(1, $this->roomsAttention('front_desk'));
        $this->assertNotNull($this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming'));
    }

    public function test_overdue_checkout_counts_one_room(): void
    {
        $this->activeStay(['expected_check_out' => '2026-08-18 14:30:00']);

        $this->assertSame(1, $this->roomsAttention('admin'));
        $this->assertNotNull($this->firstType($this->notifyAs('admin'), 'checkout_overdue'));
    }

    public function test_cleaning_room_counts_one(): void
    {
        $this->makeRoom(['status' => 'cleaning', 'room_number' => '04']);

        $this->assertSame(1, $this->roomsAttention('front_desk'));
        $this->assertSame(1, $this->roomsAttention('housekeeping'));
    }

    public function test_upcoming_checkout_plus_cleaning_counts_two_unique_rooms(): void
    {
        $this->activeStay(['expected_check_out' => '2026-08-18 15:22:00']);
        $this->makeRoom(['status' => 'cleaning', 'room_number' => '04']);

        $this->assertSame(2, $this->roomsAttention('front_desk'));
        $this->assertSame(2, $this->roomsAttention('housekeeping'));
    }

    public function test_same_room_with_multiple_matching_alerts_counts_once(): void
    {
        $room = $this->makeRoom(['status' => 'occupied', 'room_number' => '03']);
        $this->activeStayOn($room, ['expected_check_out' => '2026-08-18 15:20:00']);
        $this->activeStayOn($room, [
            'guest_name' => 'Second Stay',
            'expected_check_out' => '2026-08-18 15:40:00',
        ]);

        $items = $this->notifyAs('front_desk');
        $checkoutCount = count(array_filter(
            $items,
            fn (array $item) => in_array($item['type'] ?? '', ['checkout_upcoming', 'checkout_overdue'], true)
        ));
        $this->assertGreaterThan(1, $checkoutCount);
        $this->assertSame(1, $this->roomsAttention('front_desk'));
        $this->assertSame(1, $this->roomsAttention('housekeeping'));
    }

    public function test_cleaning_to_vacant_removes_room_from_badge(): void
    {
        $room = $this->makeRoom(['status' => 'cleaning', 'room_number' => '07']);
        $this->assertSame(1, $this->roomsAttention('front_desk'));

        $room->update(['status' => 'vacant']);
        $this->assertSame(0, $this->roomsAttention('front_desk'));
        $this->assertNull($this->firstType($this->notifyAs('front_desk'), 'cleaning_finished'));
    }

    public function test_upcoming_checkout_then_cleaning_keeps_room_counted_once(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 15:22:00']);
        $room = $booking->room;
        $this->assertSame(1, $this->roomsAttention('front_desk'));

        $booking->update(['status' => 'checked_out']);
        $room->update(['status' => 'cleaning']);

        $this->assertSame(1, $this->roomsAttention('front_desk'));
        $this->assertNull($this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming'));
        $this->assertNotNull($this->firstType($this->notifyAs('front_desk'), 'cleaning_required'));
    }

    public function test_extending_expected_checkout_beyond_warning_window_clears_badge(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 15:22:00']);
        $this->assertSame(1, $this->roomsAttention('admin'));

        $booking->update(['expected_check_out' => '2026-08-18 18:00:00']);
        $this->assertSame(0, $this->roomsAttention('admin'));
        $this->assertSame(0, $this->roomsAttention('housekeeping'));
    }

    public function test_maintenance_ticket_does_not_inflate_rooms_badge(): void
    {
        $room = $this->makeRoom(['status' => 'vacant', 'room_number' => '09']);
        MaintenanceTicket::create([
            'room_id' => $room->id,
            'title' => 'Leaky faucet',
            'description' => 'Needs repair',
            'priority' => 'high',
            'status' => 'open',
            'reported_by' => $this->user('front_desk')->id,
        ]);

        $this->assertSame(0, $this->roomsAttention('front_desk'));
        $this->assertNotNull($this->firstType($this->notifyAs('front_desk'), 'maintenance'));
    }

    public function test_example_mix_matches_client_badge_of_three(): void
    {
        $this->activeStay(['expected_check_out' => '2026-08-18 20:00:00']);
        $this->activeStay(['expected_check_out' => '2026-08-18 18:00:00']);
        $this->activeStay(['expected_check_out' => '2026-08-18 15:22:00']);
        $this->makeRoom(['status' => 'cleaning', 'room_number' => '04']);
        $this->activeStay(['expected_check_out' => '2026-08-18 14:00:00']);
        $this->makeRoom(['status' => 'vacant', 'room_number' => '06']);
        $this->makeRoom(['status' => 'vacant', 'room_number' => '07']);
        $this->makeRoom(['status' => 'vacant', 'room_number' => '08']);

        $this->assertSame(3, $this->roomsAttention('front_desk'));
        $this->assertSame(3, $this->roomsAttention('housekeeping'));
    }

    private function roomsAttention(string $role): int
    {
        $payload = $this->actingAs($this->user($role))
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->json();

        return (int) ($payload['counts']['rooms_attention'] ?? -1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifyAs(string $role): array
    {
        return $this->actingAs($this->user($role))
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->json('items');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'username' => $role.'_badge_'.uniqid(),
            'full_name' => ucfirst(str_replace('_', ' ', $role)).' Badge Staff',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function activeStay(array $overrides = []): Booking
    {
        $room = $this->makeRoom(['status' => 'occupied']);

        return $this->activeStayOn($room, $overrides);
    }

    private function activeStayOn(Room $room, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'BA'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'guest_name' => 'Badge Guest',
            'num_guests' => 1,
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'check_in' => '2026-08-18 13:00:00',
            'expected_check_out' => '2026-08-18 16:00:00',
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 400,
            'total_amount' => 400,
            'amount_paid' => 400,
            'payment_method' => 'cash',
        ], $overrides));
    }

    private function makeRoom(array $overrides = []): Room
    {
        $type = RoomType::first() ?? RoomType::create([
            'type_name' => 'Badge Room Type',
            'base_rate' => 800,
            'hourly_rate' => 150,
            'max_occupancy' => 2,
        ]);

        return Room::create(array_merge([
            'room_number' => 'B'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function firstType(array $items, string $type): ?array
    {
        foreach ($items as $item) {
            if (($item['type'] ?? null) === $type) {
                return $item;
            }
        }

        return null;
    }
}
