<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedNotificationTest extends TestCase
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

    public function test_active_booking_exactly_60_minutes_from_expected_checkout_notifies_all_roles(): void
    {
        $booking = $this->activeStay([
            'expected_check_out' => '2026-08-18 16:00:00',
            'check_out' => '2026-08-18 18:50:00',
            'guest_name' => 'Juan Dela Cruz',
        ]);

        foreach (['admin', 'front_desk'] as $role) {
            $items = $this->notifyAs($role);
            $this->assertNotNull($this->firstType($items, 'checkout_upcoming'));
            $alert = $this->firstType($items, 'checkout_upcoming');
            $this->assertSame('checkout-upcoming-'.$booking->id, $alert['alert_key']);
            $this->assertSame($booking->id, $alert['booking_id']);
            $this->assertSame('Juan Dela Cruz', $alert['guest_name']);
            $this->assertSame('Upcoming Checkout', $alert['title']);
            $this->assertStringContainsString('View Stay', $alert['action_label']);
            $this->assertStringContainsString('/bookings/'.$booking->id, $alert['action_url']);
            $this->assertStringContainsString('Juan Dela Cruz', $alert['message']);
        }

        $hk = $this->firstType($this->notifyAs('housekeeping'), 'checkout_upcoming');
        $this->assertNotNull($hk);
        $this->assertSame('Upcoming Room Checkout', $hk['title']);
        $this->assertArrayNotHasKey('guest_name', $hk);
        $this->assertStringContainsString('Prepare for room turnover.', $hk['message']);
        $this->assertStringContainsString('rooms', $hk['action_url']);
        $this->assertStringNotContainsString('/bookings/', $hk['action_url']);
    }

    public function test_booking_61_minutes_away_does_not_create_checkout_alert(): void
    {
        $this->activeStay(['expected_check_out' => '2026-08-18 16:01:00']);

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $items = $this->notifyAs($role);
            $this->assertNull($this->firstType($items, 'checkout_upcoming'));
            $this->assertNull($this->firstType($items, 'checkout_overdue'));
        }
    }

    public function test_modified_checkout_warning_uses_expected_check_out_not_paid_package_time(): void
    {
        $this->activeStay([
            'expected_check_out' => '2026-08-18 16:00:00',
            'check_out' => '2026-08-18 18:50:00',
        ]);

        $alert = $this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming');
        $this->assertNotNull($alert);
        $this->assertSame('2026-08-18 16:00:00', $alert['expected_check_out']);
        $this->assertStringContainsString('4:00 PM', $alert['message']);
        $this->assertStringNotContainsString('6:50 PM', $alert['message']);
    }

    public function test_extended_stay_outside_warning_window_drops_checkout_alert(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 16:00:00']);
        $this->assertNotNull($this->firstType($this->notifyAs('admin'), 'checkout_upcoming'));

        $booking->update(['expected_check_out' => '2026-08-18 17:30:00']);

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $items = $this->notifyAs($role);
            $this->assertNull($this->firstType($items, 'checkout_upcoming'));
            $this->assertNull($this->firstType($items, 'checkout_overdue'));
        }
    }

    public function test_overdue_active_stay_notifies_all_roles_with_role_safe_payloads(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 14:48:00']);

        foreach (['admin', 'front_desk'] as $role) {
            $alert = $this->firstType($this->notifyAs($role), 'checkout_overdue');
            $this->assertNotNull($alert);
            $this->assertSame('Overdue Checkout', $alert['title']);
            $this->assertSame('checkout-overdue-'.$booking->id, $alert['alert_key']);
            $this->assertStringContainsString('Juan Dela Cruz', $alert['guest_name'] ?? '');
            $this->assertStringContainsString('/bookings/'.$booking->id, $alert['action_url']);
        }

        $hk = $this->firstType($this->notifyAs('housekeeping'), 'checkout_overdue');
        $this->assertNotNull($hk);
        $this->assertSame('Room Checkout Overdue', $hk['title']);
        $this->assertArrayNotHasKey('guest_name', $hk);
        $this->assertStringNotContainsString('/bookings/', $hk['action_url']);
    }

    public function test_checked_out_stay_has_no_active_checkout_notification(): void
    {
        $this->activeStay([
            'status' => 'checked_out',
            'expected_check_out' => '2026-08-18 16:00:00',
        ]);

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $items = $this->notifyAs($role);
            $this->assertNull($this->firstType($items, 'checkout_upcoming'));
            $this->assertNull($this->firstType($items, 'checkout_overdue'));
        }
    }

    public function test_housekeeping_payload_does_not_expose_payment_or_balance_information(): void
    {
        $this->activeStay([
            'expected_check_out' => '2026-08-18 16:00:00',
            'amount_paid' => 1800,
            'total_amount' => 1800,
            'payment_method' => 'cash',
        ]);
        InventoryItem::create([
            'item_name' => 'Secret Stock',
            'category' => 'minibar',
            'unit' => 'pc',
            'current_stock' => 0,
            'minimum_stock' => 5,
            'unit_cost' => 10,
            'selling_price' => 40,
            'is_active' => true,
        ]);

        $payload = $this->actingAs($this->user('housekeeping'))
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->json();

        $encoded = json_encode($payload['items']);
        foreach (['amount_paid', 'total_amount', 'guest_balance', 'deposit', 'payment_method', 'cash_amount', 'guest_name'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $encoded, "Housekeeping payload leaked {$forbidden}");
        }

        $this->assertNull($this->firstType($payload['items'], 'low_stock'));
        $this->assertNull($this->firstType($payload['items'], 'out_of_stock'));
        $this->assertNull($this->firstType($payload['items'], 'inventory_request'));
    }

    public function test_room_in_cleaning_notifies_housekeeping_needs_cleaning(): void
    {
        $room = $this->makeRoom(['status' => 'cleaning', 'room_number' => '05']);

        $alert = $this->firstType($this->notifyAs('housekeeping'), 'cleaning_required');
        $this->assertNotNull($alert);
        $this->assertSame('room-cleaning-'.$room->id, $alert['alert_key']);
        $this->assertSame('Room Needs Cleaning', $alert['title']);
        $this->assertStringContainsString('turnover cleaning', $alert['message']);
    }

    public function test_room_ready_after_cleaning_does_not_appear_in_the_bell(): void
    {
        $room = $this->makeRoom(['status' => 'vacant', 'room_number' => '05']);
        AuditLog::create([
            'user_id' => $this->user('housekeeping')->id,
            'action' => 'ROOM_STATUS_CHANGE',
            'module' => 'rooms',
            'record_id' => $room->id,
            'old_value' => 'cleaning',
            'new_value' => 'vacant',
            'reason' => 'Room 05 cleaned',
        ]);

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $payload = $this->actingAs($this->user($role))
                ->getJson(route('api.notifications'))
                ->assertOk()
                ->json();
            $this->assertNull($this->firstType($payload['items'], 'cleaning_finished'));
            $this->assertSame(0, $payload['counts']['cleaning_finished']);
            $this->assertSame(0, $payload['counts']['rooms_attention']);
            $encoded = json_encode($payload['items']);
            $this->assertStringNotContainsString('Room Ready', $encoded);
            $this->assertStringNotContainsString('ready for check-in', $encoded);
            $this->assertStringNotContainsString('Cleaning Completed', $encoded);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ROOM_STATUS_CHANGE',
            'record_id' => $room->id,
            'old_value' => 'cleaning',
            'new_value' => 'vacant',
        ]);
    }

    public function test_alert_keys_stay_stable_across_polls_and_upcoming_transitions_once_to_overdue(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 16:00:00']);

        $first = $this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming');
        $second = $this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming');
        $this->assertSame($first['alert_key'], $second['alert_key']);
        $this->assertSame('checkout-upcoming-'.$booking->id, $first['alert_key']);

        Carbon::setTestNow(Carbon::parse('2026-08-18 16:01:00', HotelDateTime::TIMEZONE));

        $overdue = $this->firstType($this->notifyAs('front_desk'), 'checkout_overdue');
        $this->assertNotNull($overdue);
        $this->assertSame('checkout-overdue-'.$booking->id, $overdue['alert_key']);
        $this->assertNotSame($first['alert_key'], $overdue['alert_key']);
        $this->assertNull($this->firstType($this->notifyAs('front_desk'), 'checkout_upcoming'));
    }

    public function test_first_load_inside_warning_window_includes_checkout_alert(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 16:00:00']);

        $response = $this->actingAs($this->user('admin'))->getJson(route('api.notifications'));
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(
            'checkout-upcoming-'.$booking->id,
            $this->firstType($response->json('items'), 'checkout_upcoming')['alert_key']
        );
    }

    public function test_housekeeping_cannot_open_financial_booking_pages(): void
    {
        $booking = $this->activeStay(['expected_check_out' => '2026-08-18 16:00:00']);
        $housekeeper = $this->user('housekeeping');

        $this->actingAs($housekeeper)
            ->get(route('bookings.show', $booking))
            ->assertForbidden();

        $this->actingAs($housekeeper)
            ->get(route('reports.index'))
            ->assertForbidden();

        $alert = $this->firstType($this->notifyAs('housekeeping'), 'checkout_upcoming');
        $this->assertSame(route('rooms.index'), $alert['action_url']);
    }

    public function test_housekeeping_upcoming_includes_incoming_reservation_when_available(): void
    {
        $stay = $this->activeStay(['expected_check_out' => '2026-08-18 16:00:00']);
        Booking::create([
            'booking_ref' => 'NX'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $stay->room_id,
            'guest_name' => 'Next Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'check_in' => '2026-08-18 17:00:00',
            'expected_check_out' => '2026-08-19 12:00:00',
            'status' => 'reserved',
            'payment_status' => 'partial',
            'base_amount' => 1200,
            'total_amount' => 1200,
            'amount_paid' => 600,
            'payment_method' => 'cash',
        ]);

        $hk = $this->firstType($this->notifyAs('housekeeping'), 'checkout_upcoming');
        $this->assertStringContainsString('Incoming reservation: 5:00 PM', $hk['message']);
        $this->assertStringContainsString('20-minute turnover window after checkout', $hk['message']);
        $this->assertStringNotContainsString('Next Guest', $hk['message']);
        $this->assertStringNotContainsString('1200', $hk['message']);
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
            'username' => $role.'_notify_'.uniqid(),
            'full_name' => ucfirst(str_replace('_', ' ', $role)).' Staff',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function activeStay(array $overrides = []): Booking
    {
        $room = $this->makeRoom(['status' => 'occupied']);
        $now = HotelDateTime::now();

        return Booking::create(array_merge([
            'booking_ref' => 'CK'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'guest_name' => 'Juan Dela Cruz',
            'num_guests' => 1,
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'check_in' => '2026-08-18 13:00:00',
            'check_out' => '2026-08-18 18:50:00',
            'expected_check_out' => $now->copy()->addHour()->format('Y-m-d H:i:s'),
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
            'type_name' => 'Notify Room Type',
            'base_rate' => 800,
            'hourly_rate' => 150,
            'max_occupancy' => 2,
        ]);

        return Room::create(array_merge([
            'room_number' => 'N'.substr(uniqid(), -4),
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
