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

class ModifiedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 15:50:00', HotelDateTime::TIMEZONE));

        $this->user = User::create([
            'username' => 'mod_co_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Modified Checkout Desk',
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
        $this->roomType = RoomType::create([
            'type_name' => 'Modified Checkout Room',
            'base_rate' => 750,
            'hourly_rate' => 200,
            'short_time_3h_rate' => 400,
            'short_time_6h_rate' => 550,
            'short_time_12h_rate' => 700,
            'short_time_24h_rate' => 900,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'MC-1',
            'room_type_id' => $this->roomType->id,
            'status' => 'vacant',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_3_hour_package_with_upcoming_reservation_uses_modified_checkout_and_full_rate(): void
    {
        $reservation = $this->upcomingReservation();

        $this->assertCalculateRequiresModified(3, 400, '2026-08-26 18:50:00');

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T16:40',
            'cash_received' => 400,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame('short_time', $booking->booking_type);
        $this->assertSame(3, $booking->short_time_hours);
        $this->assertSame(400.0, (float) $booking->base_amount);
        $this->assertSame(400.0, (float) $booking->total_amount);
        $this->assertSame('2026-08-26 16:40:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertStringContainsString('incoming reservation at 5:00 PM', (string) $booking->notes);
        $this->assertStringContainsString('full 3-hour package', (string) $booking->notes);
        $this->assertSame('3 hours (paid package)', BookingService::durationLabel(
            $booking->booking_type,
            $booking->num_nights,
            $booking->short_time_hours,
            $booking->getRawOriginal('check_in'),
            $booking->getRawOriginal('expected_check_out')
        ));

        $reservation->refresh();
        $this->assertSame('reserved', $reservation->status);
        $this->assertSame('2026-08-26 17:00:00', $reservation->getRawOriginal('check_in'));
    }

    public function test_6_hour_package_charges_full_rate_with_modified_checkout(): void
    {
        $this->upcomingReservation();

        $this->assertCalculateRequiresModified(6, 550, '2026-08-26 21:50:00');

        $this->storeWalkIn([
            'short_time_hours' => 6,
            'modified_check_out' => '2026-08-26T16:40',
            'cash_received' => 550,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame(6, $booking->short_time_hours);
        $this->assertSame(550.0, (float) $booking->total_amount);
        $this->assertSame('2026-08-26 16:40:00', $booking->getRawOriginal('expected_check_out'));
    }

    public function test_12_hour_package_charges_full_rate_with_modified_checkout(): void
    {
        $this->upcomingReservation();

        $this->assertCalculateRequiresModified(12, 700, '2026-08-27 03:50:00');

        $this->storeWalkIn([
            'short_time_hours' => 12,
            'modified_check_out' => '2026-08-26T16:40',
            'cash_received' => 700,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame(12, $booking->short_time_hours);
        $this->assertSame(700.0, (float) $booking->total_amount);
        $this->assertSame('2026-08-26 16:40:00', $booking->getRawOriginal('expected_check_out'));
    }

    public function test_no_future_reservation_keeps_standard_short_time_checkout(): void
    {
        $this->actingAs($this->user)->postJson(route('checkin.calculate'), $this->calculatePayload(3))
            ->assertOk()
            ->assertJsonPath('totals.expected_check_out', '2026-08-26 18:50:00')
            ->assertJsonPath('totals.requires_modified_checkout', false)
            ->assertJsonPath('totals.total_amount', 400);

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'cash_received' => 400,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame('2026-08-26 18:50:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertSame(400.0, (float) $booking->total_amount);
        $this->assertSame('3 hours', BookingService::durationLabel('short_time', 1, 3));
    }

    public function test_modified_checkout_exactly_at_latest_safe_time_is_accepted(): void
    {
        $this->upcomingReservation();

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T16:40',
            'cash_received' => 400,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame('2026-08-26 16:40:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertSame(400.0, (float) $booking->total_amount);
    }

    public function test_modified_checkout_one_minute_after_latest_safe_time_is_rejected(): void
    {
        $this->upcomingReservation();

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T16:41',
            'cash_received' => 400,
        ])->assertSessionHasErrors(['modified_check_out']);

        $this->assertNull(Booking::where('guest_name', 'Gap Walk-In')->first());
    }

    public function test_earlier_modified_checkout_is_accepted_with_full_package_rate(): void
    {
        $this->upcomingReservation();

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T16:20',
            'cash_received' => 400,
        ])->assertRedirect(route('checkin.index'));

        $booking = Booking::where('guest_name', 'Gap Walk-In')->firstOrFail();
        $this->assertSame('2026-08-26 16:20:00', $booking->getRawOriginal('expected_check_out'));
        $this->assertSame(3, $booking->short_time_hours);
        $this->assertSame(400.0, (float) $booking->total_amount);
    }

    public function test_modified_checkout_after_next_reservation_is_rejected(): void
    {
        $this->upcomingReservation();

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T17:15',
            'cash_received' => 400,
        ])->assertSessionHasErrors(['modified_check_out']);

        $this->assertNull(Booking::where('guest_name', 'Gap Walk-In')->first());
    }

    public function test_modified_checkout_before_check_in_is_rejected(): void
    {
        $this->upcomingReservation();

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T15:00',
            'cash_received' => 400,
        ])->assertSessionHasErrors(['modified_check_out']);
    }

    public function test_occupied_and_cleaning_rooms_are_not_temporarily_available(): void
    {
        $this->upcomingReservation();
        $occupied = Room::create([
            'room_number' => 'MC-OCC',
            'room_type_id' => $this->roomType->id,
            'status' => 'occupied',
        ]);
        $cleaning = Room::create([
            'room_number' => 'MC-CLN',
            'room_type_id' => $this->roomType->id,
            'status' => 'cleaning',
        ]);

        $ids = $this->availableCheckInRoomIds(3);

        $this->assertContains($this->room->id, $ids);
        $this->assertNotContains($occupied->id, $ids);
        $this->assertNotContains($cleaning->id, $ids);
    }

    public function test_temporarily_available_room_is_flagged_until_the_next_reservation(): void
    {
        $this->upcomingReservation();

        $response = $this->actingAs($this->user)->postJson(route('reservations.available_rooms'), [
            'check_in' => '2026-08-26 15:50:00',
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'purpose' => 'checkin',
        ])->assertOk();

        $room = collect($response->json('available_rooms'))->firstWhere('id', $this->room->id);
        $this->assertNotNull($room);
        $this->assertTrue($room['temporarily_available']);
        $this->assertSame('2026-08-26 17:00:00', $room['next_reserved_check_in']);
        $this->assertSame('2026-08-26 16:40:00', $room['safe_checkout_cutoff']);
        $this->assertSame(20, $room['turnover_buffer_minutes']);
    }

    public function test_stale_modified_checkout_is_rejected_after_a_new_reservation_is_created(): void
    {
        $this->upcomingReservation();

        Booking::create([
            'booking_ref' => 'RES-NEWER',
            'room_id' => $this->room->id,
            'guest_name' => 'Earlier Arrival',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-08-26 16:30:00',
            'expected_check_out' => '2026-08-27 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'paid',
            'base_amount' => 750,
            'total_amount' => 750,
            'amount_paid' => 750,
        ]);

        $this->storeWalkIn([
            'short_time_hours' => 3,
            'modified_check_out' => '2026-08-26T16:40',
            'cash_received' => 400,
        ])->assertSessionHasErrors(['modified_check_out']);
    }

    public function test_modified_stay_cannot_be_extended_into_the_next_reservation(): void
    {
        $this->upcomingReservation();
        $booking = Booking::create([
            'booking_ref' => 'BKG-TRUNC',
            'room_id' => $this->room->id,
            'guest_name' => 'Truncated Guest',
            'num_guests' => 1,
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'check_in' => '2026-08-26 15:50:00',
            'expected_check_out' => '2026-08-26 16:40:00',
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 400,
            'total_amount' => 400,
            'amount_paid' => 400,
        ]);
        $this->room->update(['status' => 'occupied']);

        $this->actingAs($this->user)->postJson(route('bookings.preview_extend', $booking), [
            'hours' => 3,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['hours']);
    }

    public function test_24_hours_and_overnight_are_not_truncated_into_the_gap(): void
    {
        $this->upcomingReservation();

        $this->actingAs($this->user)->postJson(route('reservations.available_rooms'), [
            'check_in' => '2026-08-26 15:50:00',
            'booking_type' => 'short_time',
            'short_time_hours' => 24,
            'purpose' => 'checkin',
        ])->assertOk();
        $this->assertNotContains($this->room->id, $this->availableCheckInRoomIds(24));

        $overnightIds = collect($this->actingAs($this->user)->postJson(route('reservations.available_rooms'), [
            'check_in' => '2026-08-26 15:50:00',
            'booking_type' => 'overnight',
            'num_nights' => 2,
            'purpose' => 'checkin',
        ])->assertOk()->json('available_rooms'))->pluck('id')->all();
        $this->assertNotContains($this->room->id, $overnightIds);

        $this->actingAs($this->user)->post(route('checkin.store'), [
            'room_ids' => [$this->room->id],
            'check_in' => '2026-08-26T15:50',
            'guest_name' => 'Overnight Gap Guest',
            'booking_type' => 'overnight',
            'num_nights' => 2,
            'discount_type' => 'none',
            'payment_method' => 'cash',
            'cash_received' => 1500,
        ])->assertSessionHasErrors(['room_ids']);
    }

    public function test_turnover_buffer_is_20_minutes(): void
    {
        $this->assertSame(20, BookingService::turnoverBufferMinutes());
        $this->assertSame(
            '2026-08-26 16:40:00',
            BookingService::safeCheckoutCutoff('2026-08-26 17:00:00')
        );
    }

    private function assertCalculateRequiresModified(int $hours, int $amount, string $standardCheckout): void
    {
        $this->actingAs($this->user)->postJson(route('checkin.calculate'), $this->calculatePayload($hours))
            ->assertOk()
            ->assertJsonPath('totals.expected_check_out', $standardCheckout)
            ->assertJsonPath('totals.standard_expected_check_out', $standardCheckout)
            ->assertJsonPath('totals.requires_modified_checkout', true)
            ->assertJsonPath('totals.next_reserved_check_in', '2026-08-26 17:00:00')
            ->assertJsonPath('totals.safe_checkout_cutoff', '2026-08-26 16:40:00')
            ->assertJsonPath('totals.turnover_buffer_minutes', 20)
            ->assertJsonPath('totals.total_amount', $amount)
            ->assertJsonPath('totals.base_amount', $amount);
    }

    private function calculatePayload(int $hours): array
    {
        return [
            'room_ids' => [$this->room->id],
            'check_in' => '2026-08-26 15:50:00',
            'booking_type' => 'short_time',
            'short_time_hours' => $hours,
        ];
    }

    private function storeWalkIn(array $overrides)
    {
        return $this->actingAs($this->user)->post(route('checkin.store'), array_merge([
            'room_ids' => [$this->room->id],
            'check_in' => '2026-08-26T15:50',
            'guest_name' => 'Gap Walk-In',
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'discount_type' => 'none',
            'payment_method' => 'cash',
            'modified_checkout_acknowledged' => true,
        ], $overrides));
    }

    private function availableCheckInRoomIds(int $hours): array
    {
        $response = $this->actingAs($this->user)->postJson(route('reservations.available_rooms'), [
            'check_in' => '2026-08-26 15:50:00',
            'booking_type' => 'short_time',
            'short_time_hours' => $hours,
            'purpose' => 'checkin',
        ])->assertOk();

        return collect($response->json('available_rooms'))->pluck('id')->all();
    }

    private function upcomingReservation(): Booking
    {
        return Booking::create([
            'booking_ref' => 'RES-FIVE',
            'room_id' => $this->room->id,
            'guest_name' => 'Reserved Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-08-26 17:00:00',
            'expected_check_out' => '2026-08-27 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'paid',
            'base_amount' => 750,
            'total_amount' => 750,
            'amount_paid' => 750,
        ]);
    }
}
