<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\RoomType;
use App\Models\Room;
use App\Models\Booking;
use App\Models\ShiftSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GroupBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_bookings_are_returned_and_grouped()
    {
        // 1. Create a user
        $user = User::create([
            'username' => 'admin_test',
            'password' => bcrypt('password'),
            'full_name' => 'Admin Test',
            'role' => 'admin',
            'is_active' => true,
        ]);

        // 2. Start a shift session
        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
        ]);

        // 3. Create helper models (RoomType, Room)
        $roomType = RoomType::create([
            'type_name' => 'Deluxe Room',
            'base_rate' => 1500.00,
            'hourly_rate' => 300.00,
            'max_occupancy' => 2,
        ]);

        $room1 = Room::create([
            'room_number' => '101',
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        $room2 = Room::create([
            'room_number' => '102',
            'room_type_id' => $roomType->id,
            'status' => 'vacant',
        ]);

        // 4. Create bookings sharing a group_ref
        $groupRef = 'GRP-TEST1234';
        $booking1 = Booking::create([
            'booking_ref' => 'REF001',
            'group_ref' => $groupRef,
            'room_id' => $room1->id,
            'guest_name' => 'Guest Group',
            'check_in' => now()->format('Y-m-d H:i:s'),
            'expected_check_out' => now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'reserved',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'paid',
            'base_amount' => 1500.00,
            'total_amount' => 1500.00,
            'amount_paid' => 1500.00,
            'checked_in_by' => $user->id,
        ]);

        $booking2 = Booking::create([
            'booking_ref' => 'REF002',
            'group_ref' => $groupRef,
            'room_id' => $room2->id,
            'guest_name' => 'Guest Group',
            'check_in' => now()->format('Y-m-d H:i:s'),
            'expected_check_out' => now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'reserved',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'paid',
            'base_amount' => 1500.00,
            'total_amount' => 1500.00,
            'amount_paid' => 1500.00,
            'checked_in_by' => $user->id,
        ]);

        // 5. Test Check-In index endpoint returns groupBookings
        $response = $this->actingAs($user)->get(route('checkin.index'));
        $response->assertStatus(200);
        $this->assertArrayHasKey($groupRef, (array)$response->viewData('page')['props']['groupBookings']);

        // 6. Test Reservations index endpoint returns groupBookings
        $response2 = $this->actingAs($user)->get(route('reservations.index'));
        $response2->assertStatus(200);
        $this->assertArrayHasKey($groupRef, (array)$response2->viewData('page')['props']['groupBookings']);

        // 7. Perform group check-in
        $checkinResponse = $this->actingAs($user)->post(route('reservations.group_checkin', $groupRef));
        $checkinResponse->assertRedirect(route('rooms.index'));

        // Assert bookings became active and rooms occupied
        $this->assertEquals('active', $booking1->fresh()->status);
        $this->assertEquals('active', $booking2->fresh()->status);
        $this->assertEquals('occupied', $room1->fresh()->status);
        $this->assertEquals('occupied', $room2->fresh()->status);

        // 8. Perform group check-out
        $checkoutResponse = $this->actingAs($user)->post(route('reservations.group_checkout', $groupRef));
        $checkoutResponse->assertRedirect(route('rooms.index'));

        // Assert bookings became checked_out and rooms cleaning
        $this->assertEquals('checked_out', $booking1->fresh()->status);
        $this->assertEquals('checked_out', $booking2->fresh()->status);
        $this->assertEquals('cleaning', $room1->fresh()->status);
        $this->assertEquals('cleaning', $room2->fresh()->status);
    }

    public function test_group_checkout_settle_with_outstanding_balance()
    {
        // 1. Create a user
        $user = User::create([
            'username' => 'cashier_test',
            'password' => bcrypt('password'),
            'full_name' => 'Cashier Test',
            'role' => 'front_desk',
            'is_active' => true,
        ]);

        // 2. Start a shift session
        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
        ]);

        $roomType = RoomType::create([
            'type_name' => 'Standard Room',
            'base_rate' => 1000.00,
            'hourly_rate' => 200.00,
            'max_occupancy' => 2,
        ]);

        $room1 = Room::create([
            'room_number' => '201',
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        $room2 = Room::create([
            'room_number' => '202',
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        $groupRef = 'GRP-TEST999';

        $booking1 = Booking::create([
            'booking_ref' => 'REF201',
            'group_ref' => $groupRef,
            'room_id' => $room1->id,
            'guest_name' => 'Group Settle Test',
            'check_in' => now()->format('Y-m-d H:i:s'),
            'expected_check_out' => now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'partial',
            'base_amount' => 1000.00,
            'total_amount' => 1000.00,
            'amount_paid' => 800.00, // 200 unpaid
            'checked_in_by' => $user->id,
        ]);

        $booking2 = Booking::create([
            'booking_ref' => 'REF202',
            'group_ref' => $groupRef,
            'room_id' => $room2->id,
            'guest_name' => 'Group Settle Test',
            'check_in' => now()->format('Y-m-d H:i:s'),
            'expected_check_out' => now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'partial',
            'base_amount' => 1000.00,
            'total_amount' => 1000.00,
            'amount_paid' => 900.00, // 100 unpaid
            'checked_in_by' => $user->id,
        ]);

        // 3. Test Preview endpoint
        $previewResponse = $this->actingAs($user)->get(route('reservations.group_checkout_preview', $groupRef));
        $previewResponse->assertStatus(200);
        $previewResponse->assertJsonFragment([
            'group_ref' => $groupRef,
        ]);
        $this->assertEquals(300.00, $previewResponse->json('totals.balance'));

        // 4. Try posting checkout settle with insufficient payment
        $insufficientResponse = $this->actingAs($user)->post(route('reservations.group_checkout_settle', $groupRef), [
            'payment_method' => 'cash',
            'cash_amount' => 200.00, // needs 300
        ]);
        $insufficientResponse->assertSessionHas('error');

        // 5. Post check out settle with correct payment
        $settleResponse = $this->actingAs($user)->post(route('reservations.group_checkout_settle', $groupRef), [
            'payment_method' => 'cash',
            'cash_amount' => 300.00,
        ]);
        $settleResponse->assertRedirect(route('rooms.index'));

        // 6. Assert both bookings are now checked out and amount_paid is fully updated
        $this->assertEquals('checked_out', $booking1->fresh()->status);
        $this->assertEquals(1000.00, $booking1->fresh()->amount_paid);
        $this->assertEquals('checked_out', $booking2->fresh()->status);
        $this->assertEquals(1000.00, $booking2->fresh()->amount_paid);

        // 7. Verify cleaning status
        $this->assertEquals('cleaning', $room1->fresh()->status);
        $this->assertEquals('cleaning', $room2->fresh()->status);

        // 8. Assert transaction traces were created
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking1->id,
            'transaction_type' => 'check_out',
            'cash_amount' => 200.00,
        ]);
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking2->id,
            'transaction_type' => 'check_out',
            'cash_amount' => 100.00,
        ]);
    }
}
