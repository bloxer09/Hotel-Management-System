<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\BookingService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayTypeRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'username' => 'stay_rules_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Stay Rules Desk',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
        ShiftSession::create([
            'user_id' => $this->user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 0,
        ]);
        $type = RoomType::create([
            'type_name' => 'Stay Rules Room',
            'base_rate' => 750,
            'hourly_rate' => 200,
            'short_time_3h_rate' => 400,
            'short_time_6h_rate' => 550,
            'short_time_12h_rate' => 700,
            'short_time_24h_rate' => 900,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'SR-1',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_9am_24_hours_checks_out_the_next_day_at_the_same_clock_time(): void
    {
        $this->assertCalculate(
            '2026-08-26 09:00:00',
            'short_time',
            24,
            1,
            '2026-08-27 09:00:00',
            900
        );
        $this->assertSame('24 hours', BookingService::durationLabel('short_time', 1, 24));
    }

    public function test_9am_overnight_one_night_is_rejected_in_favor_of_24_hours(): void
    {
        $this->assertStayTypeRejected('2026-08-26 09:00:00', 'overnight', 1);
        $this->assertSame('1 night', BookingService::durationLabel('overnight', 1, null));
    }

    public function test_9am_overnight_two_nights_preserves_clock_time(): void
    {
        $this->assertCalculate(
            '2026-08-26 09:00:00',
            'overnight',
            3,
            2,
            '2026-08-28 09:00:00',
            1500
        );
    }

    public function test_159pm_overnight_one_night_is_rejected(): void
    {
        $this->assertStayTypeRejected('2026-08-26 13:59:00', 'overnight', 1);
    }

    public function test_159pm_overnight_two_nights_preserves_clock_time(): void
    {
        $this->assertCalculate(
            '2026-08-26 13:59:00',
            'overnight',
            3,
            2,
            '2026-08-28 13:59:00',
            1500
        );
    }

    public function test_2pm_overnight_one_night_checks_out_next_day_noon(): void
    {
        $this->assertCalculate(
            '2026-08-26 14:00:00',
            'overnight',
            3,
            1,
            '2026-08-27 12:00:00',
            750
        );
    }

    public function test_201pm_overnight_one_night_checks_out_next_day_noon(): void
    {
        $this->assertCalculate(
            '2026-08-26 14:01:00',
            'overnight',
            3,
            1,
            '2026-08-27 12:00:00',
            750
        );
    }

    public function test_201pm_overnight_two_nights_checks_out_at_noon(): void
    {
        $this->assertCalculate(
            '2026-08-26 14:01:00',
            'overnight',
            3,
            2,
            '2026-08-28 12:00:00',
            1500
        );
    }

    public function test_6pm_overnight_three_nights_checks_out_at_noon(): void
    {
        $this->assertCalculate(
            '2026-08-26 18:00:00',
            'overnight',
            3,
            3,
            '2026-08-29 12:00:00',
            2250
        );
    }

    public function test_3_6_12_hour_calculations_remain_unchanged(): void
    {
        $this->assertCalculate('2026-08-26 09:00:00', 'short_time', 3, 1, '2026-08-26 12:00:00', 400);
        $this->assertCalculate('2026-08-26 09:00:00', 'short_time', 6, 1, '2026-08-26 15:00:00', 550);
        $this->assertCalculate('2026-08-26 09:00:00', 'short_time', 12, 1, '2026-08-26 21:00:00', 700);
        $this->assertCalculate('2026-08-26 18:00:00', 'short_time', 3, 1, '2026-08-26 21:00:00', 400);
    }

    public function test_early_arrival_of_overnight_reservation_keeps_type_nights_and_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:00:00', HotelDateTime::TIMEZONE));

        $booking = $this->reservedOvernight(['num_nights' => 2, 'base_amount' => 1500, 'total_amount' => 1500, 'amount_paid' => 1500]);

        $this->actingAs($this->user)
            ->post(route('reservations.checkin', $booking))
            ->assertRedirect(route('rooms.index'));

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertSame('overnight', $booking->booking_type);
        $this->assertSame(2, $booking->num_nights);
        $this->assertSame(1500.0, (float) $booking->total_amount);
        $this->assertSame('2026-08-28 09:00:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertSame('2026-08-26 09:00:00', $booking->getRawOriginal('check_in'));
    }

    public function test_overnight_reservation_arriving_at_2pm_keeps_overnight_and_rebuilds_noon_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 14:00:00', HotelDateTime::TIMEZONE));

        $booking = $this->reservedOvernight();
        $originalTotal = (float) $booking->total_amount;

        $this->actingAs($this->user)
            ->post(route('reservations.checkin', $booking))
            ->assertRedirect(route('rooms.index'));

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertSame('overnight', $booking->booking_type);
        $this->assertSame(1, $booking->num_nights);
        $this->assertSame($originalTotal, (float) $booking->total_amount);
        $this->assertSame('2026-08-27 12:00:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertSame('2026-08-26 14:00:00', $booking->getRawOriginal('check_in'));
    }

    public function test_group_early_checkin_keeps_overnight_nights_and_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:00:00', HotelDateTime::TIMEZONE));

        $room2 = Room::create([
            'room_number' => 'SR-2',
            'room_type_id' => $this->room->room_type_id,
            'status' => 'vacant',
        ]);

        $groupRef = 'GRP-EARLY';
        $booking1 = $this->reservedOvernight([
            'booking_ref' => 'RES-G1',
            'group_ref' => $groupRef,
            'num_nights' => 2,
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ]);
        $booking2 = $this->reservedOvernight([
            'booking_ref' => 'RES-G2',
            'group_ref' => $groupRef,
            'room_id' => $room2->id,
            'num_nights' => 2,
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ]);

        $this->actingAs($this->user)
            ->post(route('reservations.group_checkin', $groupRef))
            ->assertRedirect(route('rooms.index'));

        $booking1->refresh();
        $booking2->refresh();
        $this->assertSame('active', $booking1->status);
        $this->assertSame('overnight', $booking1->booking_type);
        $this->assertSame(2, $booking1->num_nights);
        $this->assertSame(1500.0, (float) $booking1->total_amount);
        $this->assertSame('2026-08-28 09:00:00', $booking1->getRawOriginal('expected_check_out'));
        $this->assertSame('overnight', $booking2->booking_type);
        $this->assertSame(2, $booking2->num_nights);
        $this->assertSame(1500.0, (float) $booking2->total_amount);
        $this->assertSame('2026-08-28 09:00:00', $booking2->getRawOriginal('expected_check_out'));
    }

    public function test_early_overnight_day_extension_preserves_the_established_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:00:00', HotelDateTime::TIMEZONE));

        $booking = Booking::create([
            'booking_ref' => 'BKG-EXT-EARLY',
            'room_id' => $this->room->id,
            'guest_name' => 'Early Extend Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 2,
            'check_in' => '2026-08-26 09:00:00',
            'expected_check_out' => '2026-08-28 09:00:00',
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
        ]);
        $this->room->update(['status' => 'occupied']);

        $this->actingAs($this->user)->postJson(route('bookings.preview_extend', $booking), [
            'days' => 1,
        ])->assertOk()->assertJson([
            'new_expected_check_out' => '2026-08-29 09:00:00',
        ]);
    }

    public function test_standard_overnight_day_extension_preserves_noon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 18:00:00', HotelDateTime::TIMEZONE));

        $booking = Booking::create([
            'booking_ref' => 'BKG-EXT-NOON',
            'room_id' => $this->room->id,
            'guest_name' => 'Extend Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-08-26 14:00:00',
            'expected_check_out' => '2026-08-27 12:00:00',
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 750,
            'total_amount' => 750,
            'amount_paid' => 750,
        ]);
        $this->room->update(['status' => 'occupied']);

        $this->actingAs($this->user)->postJson(route('bookings.preview_extend', $booking), [
            'days' => 1,
        ])->assertOk()->assertJson([
            'new_expected_check_out' => '2026-08-28 12:00:00',
        ]);
    }

    public function test_duration_labels_never_convert_24_hours_into_one_night(): void
    {
        $this->assertSame('24 hours', BookingService::durationLabel('short_time', 1, 24));
        $this->assertSame('1 night', BookingService::durationLabel('overnight', 1, null));
        $this->assertSame('2 nights', BookingService::durationLabel('overnight', 2, null));
        $this->assertSame('3 nights', BookingService::durationLabel('overnight', 3, null));
        $this->assertSame('3 hours', BookingService::durationLabel('short_time', 1, 3));
        $this->assertSame('6 hours', BookingService::durationLabel('short_time', 1, 6));
        $this->assertSame('12 hours', BookingService::durationLabel('short_time', 1, 12));
    }

    public function test_walk_in_store_rejects_overnight_one_night_before_2pm(): void
    {
        $this->actingAs($this->user)
            ->post(route('checkin.store'), [
                'room_ids' => [$this->room->id],
                'check_in' => '2026-08-26T09:00',
                'guest_name' => 'Early One Night Walk-In',
                'booking_type' => 'overnight',
                'num_nights' => 1,
                'discount_type' => 'none',
                'payment_method' => 'cash',
                'cash_received' => 750,
            ])
            ->assertSessionHasErrors(['booking_type', 'num_nights']);

        $this->assertNull(Booking::where('guest_name', 'Early One Night Walk-In')->first());
    }

    public function test_walk_in_store_allows_overnight_before_2pm(): void
    {
        $this->actingAs($this->user)
            ->post(route('checkin.store'), [
                'room_ids' => [$this->room->id],
                'check_in' => '2026-08-26T09:00',
                'guest_name' => 'Early Overnight Walk-In',
                'booking_type' => 'overnight',
                'num_nights' => 2,
                'discount_type' => 'none',
                'payment_method' => 'cash',
                'cash_received' => 1500,
            ])
            ->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Early Overnight Walk-In')->firstOrFail();
        $this->assertSame('overnight', $booking->booking_type);
        $this->assertSame(2, $booking->num_nights);
        $this->assertSame(1500.0, (float) $booking->total_amount);
        $this->assertSame('2026-08-28 09:00:00', $booking->getRawOriginal('expected_check_out'));
    }

    private function assertStayTypeRejected(string $checkIn, string $bookingType, int $nights): void
    {
        $this->actingAs($this->user)->postJson(route('reservations.calculate'), $this->calculatePayload(
            $checkIn,
            $bookingType,
            3,
            $nights
        ))->assertStatus(422)
            ->assertJsonValidationErrors(['booking_type', 'num_nights']);
    }

    private function assertCalculate(
        string $checkIn,
        string $bookingType,
        int $hours,
        int $nights,
        string $expectedCheckOut,
        float $expectedTotal
    ): void {
        $response = $this->actingAs($this->user)->postJson(route('reservations.calculate'), $this->calculatePayload(
            $checkIn,
            $bookingType,
            $hours,
            $nights
        ))->assertOk();

        $this->assertSame($expectedCheckOut, $response->json('totals.expected_check_out'));
        $this->assertEquals($expectedTotal, $response->json('totals.total_amount'));
        $this->assertEquals($expectedTotal, $response->json('totals.base_amount'));
    }

    private function calculatePayload(string $checkIn, string $bookingType, int $hours, int $nights): array
    {
        return [
            'room_ids' => [$this->room->id],
            'check_in' => $checkIn,
            'booking_type' => $bookingType,
            'num_nights' => $nights,
            'short_time_hours' => $hours,
        ];
    }

    private function reservedOvernight(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'RES-EARLY-'.strtoupper(substr(uniqid(), -6)),
            'room_id' => $this->room->id,
            'booked_by_user_id' => $this->user->id,
            'guest_name' => 'Overnight Reservation Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-08-26 14:00:00',
            'expected_check_out' => '2026-08-27 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'paid',
            'base_amount' => 750,
            'total_amount' => 750,
            'amount_paid' => 750,
        ], $overrides));
    }
}
