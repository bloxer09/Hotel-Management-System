<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftReportWalkInTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 18:00:00', HotelDateTime::TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_datetime_local_is_stored_as_manila_wall_clock(): void
    {
        $this->assertSame(
            '2026-08-17 21:00:00',
            HotelDateTime::toDatabase('2026-08-17T21:00')
        );
        $this->assertSame(
            '2026-08-17 21:00:00',
            HotelDateTime::shiftWindow('2026-08-17 13:00:00', '2026-08-17 13:10:00')[0]
        );
    }

    public function test_direct_walk_in_check_in_now_appears_on_page_one(): void
    {
        $user = $this->createCashier();
        $shift = $this->openShift($user);
        $room = $this->createVacantRoom('WI-1');
        $localNow = HotelDateTime::now()->format('Y-m-d\TH:i');

        $this->actingAs($user)->post(route('checkin.store'), [
            'room_ids' => [$room->id],
            'check_in' => $localNow,
            'guest_name' => 'Walk In Now Guest',
            'guest_contact' => '09170000001',
            'guest_id_type' => 'NONE',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'discount_type' => 'none',
            'payment_method' => 'cash',
            'cash_received' => 5000,
        ])->assertRedirect();

        $booking = Booking::where('guest_name', 'Walk In Now Guest')->firstOrFail();
        $this->assertSame($user->id, $booking->checked_in_by);
        $this->assertStringStartsWith('BKG-', $booking->booking_ref);
        $this->assertSame(
            HotelDateTime::parseLocal($localNow)->format('Y-m-d H:i:00'),
            $booking->getRawOriginal('check_in')
        );

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/RoomBookingsLedgerPrint')
                ->has('bookings', 1)
                ->where('bookings.0.id', $booking->id)
                ->where('bookings.0.checked_in_by', $user->id)
                ->where('totals.total_room_sales', 1500)
                ->where('cash_tally.room_sales_cash', 1500)
                ->has('booking_transactions', 0)
            );
    }

    public function test_other_front_desk_user_check_in_does_not_appear_on_page_one(): void
    {
        $user = $this->createCashier();
        $other = $this->createCashier();
        $shift = $this->openShift($user);
        $this->createStay($other, HotelDateTime::now()->format('Y-m-d H:i:s'), 'Other Desk Guest');

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings', 0)
            );
    }

    public function test_check_in_outside_shift_period_does_not_appear_on_page_one(): void
    {
        $user = $this->createCashier();
        $shift = $this->openShift($user);
        $this->createStay(
            $user,
            HotelDateTime::now()->subDays(2)->format('Y-m-d H:i:s'),
            'Old Stay Guest'
        );

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings', 0)
            );
    }

    public function test_reservation_checked_in_during_shift_appears_on_page_one(): void
    {
        $user = $this->createCashier();
        $shift = $this->openShift($user);
        $room = $this->createVacantRoom('RS-1');
        $reservation = Booking::create([
            'booking_ref' => 'RES-'.strtoupper(substr(uniqid(), -10)),
            'room_id' => $room->id,
            'booked_by_user_id' => $user->id,
            'guest_name' => 'Reserved Arrival Guest',
            'guest_contact' => '09170000002',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => HotelDateTime::now()->addDays(2)->format('Y-m-d H:i:s'),
            'expected_check_out' => HotelDateTime::now()->addDays(3)->format('Y-m-d H:i:s'),
            'status' => 'reserved',
            'payment_status' => 'paid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
            'checked_in_by' => null,
        ]);

        $this->actingAs($user)->post(route('reservations.checkin', $reservation))->assertRedirect();

        $reservation->refresh();
        $this->assertSame('active', $reservation->status);
        $this->assertSame($user->id, $reservation->checked_in_by);
        $this->assertSame(
            HotelDateTime::now()->format('Y-m-d H:i'),
            substr((string) $reservation->getRawOriginal('check_in'), 0, 16)
        );

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings', 1)
                ->where('bookings.0.id', $reservation->id)
                ->has('booking_transactions', 1)
                ->where('booking_transactions.0.id', $reservation->id)
            );
    }

    public function test_future_reservation_without_check_in_stays_off_page_one(): void
    {
        $user = $this->createCashier();
        $shift = $this->openShift($user);
        $room = $this->createVacantRoom('FU-1');
        $reservation = Booking::create([
            'booking_ref' => 'RES-'.strtoupper(substr(uniqid(), -10)),
            'room_id' => $room->id,
            'booked_by_user_id' => $user->id,
            'guest_name' => 'Future Reserved Guest',
            'guest_contact' => '09170000003',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => HotelDateTime::now()->addDays(3)->format('Y-m-d H:i:s'),
            'expected_check_out' => HotelDateTime::now()->addDays(4)->format('Y-m-d H:i:s'),
            'status' => 'reserved',
            'payment_status' => 'unpaid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 0,
            'checked_in_by' => $user->id,
        ]);

        app(PaymentService::class)->record([
            'payer_name' => $reservation->guest_name,
            'payment_method_code' => 'cash',
            'amount' => 750,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $user->id,
            'received_at' => now()->subMinutes(5),
        ], [$reservation->id => 750], [[
            'payment_method_code' => 'cash',
            'amount' => 750,
        ]]);

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings', 0)
                ->has('booking_transactions', 1)
                ->where('booking_transactions.0.id', $reservation->id)
                ->where('stay_collections.cash', 0)
                ->where('cash_tally.room_sales_cash', 750)
                ->where('cash_tally.expected_cash', 1750)
            );
    }

    private function createCashier(): User
    {
        return User::create([
            'username' => 'walkin_tz_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Walk-in Timezone Cashier',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
    }

    private function openShift(User $user): ShiftSession
    {
        return ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 0,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
        ]);
    }

    private function createVacantRoom(string $number): Room
    {
        $type = RoomType::create([
            'type_name' => 'Walk-in Room '.$number,
            'base_rate' => 1500,
            'hourly_rate' => 300,
            'max_occupancy' => 2,
        ]);

        return Room::create([
            'room_number' => $number,
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    private function createStay(User $user, string $checkIn, string $guestName): Booking
    {
        $room = $this->createVacantRoom('ST-'.substr(uniqid(), -4));

        return Booking::create([
            'booking_ref' => 'BKG-'.strtoupper(substr(uniqid(), -10)),
            'room_id' => $room->id,
            'booked_by_user_id' => $user->id,
            'guest_name' => $guestName,
            'guest_contact' => '09170000000',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => $checkIn,
            'expected_check_out' => HotelDateTime::now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1500,
            'total_amount' => 1500,
            'amount_paid' => 1500,
            'checked_in_by' => $user->id,
        ]);
    }
}
