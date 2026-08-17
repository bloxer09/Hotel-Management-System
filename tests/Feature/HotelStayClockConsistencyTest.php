<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HotelStayClockConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stay_fields_serialize_as_naive_hotel_local_strings(): void
    {
        $booking = $this->createStay([
            'guest_name' => 'Serialize Guest',
            'status' => 'active',
            'check_in' => '2026-08-17 21:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);

        $payload = $booking->toArray();
        $json = $booking->toJson();

        $this->assertSame('2026-08-17 21:00:00', $payload['check_in']);
        $this->assertSame('2026-08-18 12:00:00', $payload['expected_check_out']);
        $this->assertDoesNotMatchRegularExpression('/"check_in":"[^"]*Z"/', $json);
        $this->assertDoesNotMatchRegularExpression('/"expected_check_out":"[^"]*Z"/', $json);
        $this->assertMatchesRegularExpression('/"created_at":"[^"]*Z"/', $json);
    }

    public function test_dashboard_uses_hotel_calendar_dates_near_manila_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 16:30:00', 'UTC'));
        $this->assertSame('2026-08-18 00:30:00', HotelDateTime::toDatabase());

        $user = $this->createFrontDesk();
        $this->createStay([
            'guest_name' => 'Hotel Today Arrival',
            'status' => 'reserved',
            'check_in' => '2026-08-18 14:00:00',
            'expected_check_out' => '2026-08-19 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Utc Today Arrival',
            'status' => 'reserved',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Current Stay',
            'status' => 'active',
            'check_in' => HotelDateTime::toDatabase(),
            'expected_check_out' => '2026-08-19 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Upcoming Hour Arrival',
            'status' => 'reserved',
            'check_in' => '2026-08-18 01:00:00',
            'expected_check_out' => '2026-08-19 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Overdue Guest',
            'status' => 'active',
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-17 23:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Hotel Today Departure',
            'status' => 'active',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.operations.arrivals_today', 2)
                ->where('stats.operations.departures_today', 1)
                ->where('stats.operations.in_house', 3)
                ->has('upcomingCheckins', 2)
                ->where('liveUpdates', fn ($updates) => collect($updates)->contains(
                    fn ($item) => ($item['type'] ?? null) === 'overdue_checkout'
                ))
            );
    }

    public function test_checkin_and_reservation_today_filters_follow_hotel_local_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 16:30:00', 'UTC'));
        $user = $this->createFrontDesk();

        $hotelDeparture = $this->createStay([
            'guest_name' => 'Hotel Departure',
            'status' => 'active',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Utc Departure',
            'status' => 'active',
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-17 12:00:00',
        ]);
        $hotelArrival = $this->createStay([
            'guest_name' => 'Hotel Arrival',
            'status' => 'reserved',
            'check_in' => '2026-08-18 14:00:00',
            'expected_check_out' => '2026-08-19 12:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Utc Arrival',
            'status' => 'reserved',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);

        $this->actingAs($user)->get(route('checkin.index', ['status' => 'active', 'date_scope' => 'departures_today']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.data.0.id', $hotelDeparture->id)
            );

        $this->actingAs($user)->get(route('reservations.index', ['status' => 'reserved', 'date_scope' => 'arrivals_today']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reservations.data', 1)
                ->where('reservations.data.0.id', $hotelArrival->id)
            );
    }

    public function test_notifications_compare_expected_checkout_on_the_hotel_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 16:00:00', 'UTC'));
        $this->assertSame('2026-08-18 00:00:00', HotelDateTime::toDatabase());
        Cache::flush();

        $user = $this->createFrontDesk();
        $this->createStay([
            'guest_name' => 'Due In Sixty',
            'status' => 'active',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 01:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Already Overdue',
            'status' => 'active',
            'check_in' => '2026-08-16 14:00:00',
            'expected_check_out' => '2026-08-17 23:00:00',
        ]);
        $this->createStay([
            'guest_name' => 'Next Day Boundary',
            'status' => 'active',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 00:15:00',
        ]);
        $this->createStay([
            'guest_name' => 'Too Far Ahead',
            'status' => 'active',
            'check_in' => '2026-08-17 14:00:00',
            'expected_check_out' => '2026-08-18 12:00:00',
        ]);

        $response = $this->actingAs($user)->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('counts.upcoming', 2)
            ->assertJsonPath('counts.overdue', 1);

        $items = collect($response->json('items'));
        $this->assertTrue($items->contains(fn ($item) => ($item['guest_name'] ?? null) === 'Due In Sixty' && $item['type'] === 'checkout_upcoming'));
        $this->assertTrue($items->contains(fn ($item) => ($item['guest_name'] ?? null) === 'Already Overdue' && $item['type'] === 'checkout_overdue'));
        $this->assertTrue($items->contains(fn ($item) => ($item['guest_name'] ?? null) === 'Next Day Boundary' && $item['type'] === 'checkout_upcoming'));
        $this->assertFalse($items->contains(fn ($item) => ($item['guest_name'] ?? null) === 'Too Far Ahead'));
        $this->assertSame('2026-08-18 01:00:00', $items->firstWhere('guest_name', 'Due In Sixty')['expected_check_out']);
        $this->assertStringNotContainsString('Z', $items->firstWhere('guest_name', 'Due In Sixty')['expected_check_out']);
    }

    private function createFrontDesk(): User
    {
        return User::create([
            'username' => 'stay_clock_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Stay Clock Staff',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
    }

    private function createStay(array $overrides): Booking
    {
        $type = RoomType::create([
            'type_name' => 'Stay Clock '.$overrides['guest_name'],
            'base_rate' => 1500,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'SC-'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => ($overrides['status'] ?? 'reserved') === 'active' ? 'occupied' : 'vacant',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => 'BKG-'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'guest_name' => 'Stay Guest',
            'guest_contact' => '09170000000',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => HotelDateTime::toDatabase(),
            'expected_check_out' => HotelDateTime::now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ], $overrides));
    }
}
