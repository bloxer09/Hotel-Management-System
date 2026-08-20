<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InventoryAmenityIssue;
use App\Models\InventoryAmenityIssueItem;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryUsage;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\StayAmenityPolicy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AmenityIssuanceService;
use App\Services\InventoryTurnoverService;
use App\Services\InventoryUsageSettlementService;
use App\Services\ShiftCashReconciliationService;
use App\Services\ShiftService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplimentaryAmenityIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $admin;

    private Room $room;

    private InventoryItem $safeguard;

    private InventoryItem $shampoo;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-20 15:00:00', HotelDateTime::TIMEZONE));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'full_name' => 'Phase2 Admin',
        ]);
        $this->desk = User::factory()->create([
            'role' => 'front_desk',
            'username' => 'phase2_desk',
            'full_name' => 'Maria Santos',
        ]);
        ShiftSession::create([
            'user_id' => $this->desk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);

        $type = RoomType::create([
            'type_name' => 'Phase2 Room',
            'base_rate' => 750,
            'hourly_rate' => 200,
            'short_time_3h_rate' => 400,
            'short_time_6h_rate' => 550,
            'short_time_12h_rate' => 700,
            'short_time_24h_rate' => 900,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => '05',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);

        $this->safeguard = $this->item('Safeguard', 20);
        $this->shampoo = $this->item('Shampoo', 20);
        $this->configurePolicies();
        $this->acceptTrackedOpening($this->desk, [
            [$this->safeguard, 20],
            [$this->shampoo, 20],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_eligible_24_hours_stay_shows_policy_defaults_without_deducting_until_issue(): void
    {
        $this->actingAs($this->desk)
            ->get(route('checkin.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('amenityPolicies.short_time_24', 2)
                ->where('amenityPolicies.short_time_24.0.default_quantity', 1)
            );

        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(20, (int) $this->shampoo->fresh()->current_stock);

        $this->walkInCheckIn([
            'booking_type' => 'short_time',
            'short_time_hours' => 24,
            'issue_amenities' => 0,
        ]);

        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
        $this->assertSame(0, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)->count());
    }

    public function test_b_eligible_overnight_stay_shows_the_same_policy_defaults(): void
    {
        $this->actingAs($this->desk)
            ->get(route('checkin.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('amenityPolicies.overnight', 2)
            );

        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'issue_amenities' => 0,
        ]);

        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(20, (int) $this->shampoo->fresh()->current_stock);
    }

    public function test_c_four_night_overnight_initial_set_deducts_one_set_not_four(): void
    {
        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 4,
            'issue_amenities' => 1,
            'amenity_items' => $this->bothItems(),
        ]);

        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(19, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(1, InventoryAmenityIssue::count());
        $this->assertSame(2, InventoryAmenityIssueItem::count());
        $this->assertSame(2, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)->count());
    }

    public function test_d_reservation_created_but_not_issued_leaves_stock_unchanged(): void
    {
        $this->actingAs($this->desk)->post(route('reservations.store'), [
            'room_ids' => [$this->room->id],
            'check_in' => '2026-08-27 14:00:00',
            'guest_name' => 'Reserved Guest',
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'payment_ratio' => 'full',
            'payment_method' => 'cash',
            'cash_received' => 2000,
        ])->assertRedirect();

        $this->assertNotNull(Booking::where('guest_name', 'Reserved Guest')->first());
        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
    }

    public function test_e_check_in_only_does_not_deduct_stock(): void
    {
        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 4,
            'issue_amenities' => 0,
            'amenity_items' => $this->bothItems(),
        ]);

        $booking = Booking::firstOrFail();
        $this->assertSame('active', $booking->status);
        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(20, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(0, InventoryUsage::count());
    }

    public function test_f_and_g_initial_safeguard_and_shampoo_are_physical_stock_only(): void
    {
        $register = ShiftService::activeRegister();
        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 4,
            'issue_amenities' => 1,
            'amenity_items' => $this->bothItems(),
        ]);

        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(19, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(0, InventoryUsage::count());
        $this->assertSame(0, Transaction::where('transaction_type', 'pos_sale')->count());

        $issue = InventoryAmenityIssue::firstOrFail();
        $this->assertMatchesRegularExpression('/^INV-A-20260820-\d{4}$/', $issue->reference);
        $this->assertSame('initial', $issue->issue_context);
        $this->assertSame($this->desk->id, (int) $issue->issued_by);
        $this->assertSame($register->id, (int) $issue->shift_session_id);

        $movements = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame(-1, (int) $movement->quantity_change);
            $this->assertSame(20, (int) $movement->stock_before);
            $this->assertSame(19, (int) $movement->stock_after);
            $this->assertSame(InventoryAmenityIssue::SOURCE_TYPE, $movement->source_type);
            $this->assertSame($issue->id, (int) $movement->source_id);
            $this->assertSame($this->desk->id, (int) $movement->performed_by);
            $this->assertSame($register->id, (int) $movement->shift_session_id);
        }
    }

    public function test_h_multi_item_issue_is_atomic(): void
    {
        $this->safeguard->update(['current_stock' => 0]);

        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'issue_amenities' => 1,
            'amenity_items' => $this->bothItems(),
        ]);

        $this->assertSame('active', Booking::firstOrFail()->status);
        $this->assertSame(0, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(20, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
        $this->assertSame(0, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)->count());
    }

    public function test_i_second_initial_same_stay_and_product_is_rejected(): void
    {
        $booking = $this->checkedInStay();
        $this->issue($booking, 'initial', $this->bothItems());

        $this->actingAs($this->desk)
            ->from(route('bookings.show', $booking))
            ->post(route('bookings.amenities.issue', $booking), [
                'issue_context' => 'initial',
                'items' => [['inventory_item_id' => $this->safeguard->id, 'quantity' => 1]],
                'idempotency_key' => 'retry-initial-safeguard',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(1, InventoryAmenityIssue::count());
    }

    public function test_j_refill_on_day_three_uses_the_current_register_and_issuer(): void
    {
        $booking = $this->checkedInStay();
        $day1 = ShiftService::activeRegister();
        $this->issue($booking, 'initial', $this->bothItems());

        $day1->update(['ended_at' => now()->addDay()]);
        Carbon::setTestNow(Carbon::parse('2026-08-22 16:35:18', HotelDateTime::TIMEZONE));
        $day3Desk = User::factory()->create([
            'role' => 'front_desk',
            'username' => 'phase2_day3',
            'full_name' => 'Day Three Desk',
        ]);
        $day3 = ShiftSession::create([
            'user_id' => $day3Desk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 800,
            'opening_cash_minibar' => 200,
        ]);
        $this->acceptTrackedOpening($day3Desk, [
            [$this->safeguard, 19],
            [$this->shampoo, 19],
        ]);

        $this->actingAs($day3Desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'refill',
            'items' => [['inventory_item_id' => $this->safeguard->id, 'quantity' => 1]],
            'idempotency_key' => 'day3-refill-safeguard',
        ])->assertRedirect();

        $this->assertSame(18, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(19, (int) $this->shampoo->fresh()->current_stock);

        $refill = InventoryAmenityIssue::where('issue_context', 'refill')->firstOrFail();
        $this->assertSame($day3Desk->id, (int) $refill->issued_by);
        $this->assertSame($day3->id, (int) $refill->shift_session_id);
        $this->assertNotEquals($day1->id, (int) $refill->shift_session_id);

        $movement = InventoryStockMovement::query()
            ->where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)
            ->where('inventory_item_id', $this->safeguard->id)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame($day3->id, (int) $movement->shift_session_id);
        $this->assertSame($day3Desk->id, (int) $movement->performed_by);
        $this->assertSame(-1, (int) $movement->quantity_change);
    }

    public function test_k_room_transfer_does_not_create_another_initial_issue(): void
    {
        $booking = $this->checkedInStay();
        $this->issue($booking, 'initial', $this->bothItems());
        $type = RoomType::first();
        $newRoom = Room::create([
            'room_number' => '07',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);

        $this->actingAs($this->desk)->post(route('bookings.move', $booking), [
            'new_room_id' => $newRoom->id,
            'reason' => 'Guest request',
        ])->assertRedirect();

        $this->assertSame(1, InventoryAmenityIssue::count());
        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);

        $this->actingAs($this->desk)
            ->post(route('bookings.amenities.issue', $booking->fresh()), [
                'issue_context' => 'initial',
                'items' => $this->bothItems(),
                'idempotency_key' => 'after-transfer-initial',
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, InventoryAmenityIssue::count());
        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
    }

    public function test_l_booking_cancel_after_complimentary_issue_does_not_restore_stock(): void
    {
        $booking = $this->checkedInStay();
        $this->issue($booking, 'initial', $this->bothItems());

        $this->actingAs($this->desk)->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left',
        ])->assertRedirect();

        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(19, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_BOOKING_REVERSAL)->count());
        $this->assertSame(2, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)->count());
        $this->assertSame(1, InventoryAmenityIssue::count());
    }

    public function test_m_out_of_stock_line_fails_the_entire_multi_item_issue(): void
    {
        $booking = $this->checkedInStay();
        $this->shampoo->update(['current_stock' => 0]);

        $this->actingAs($this->desk)
            ->from(route('bookings.show', $booking))
            ->post(route('bookings.amenities.issue', $booking), [
                'issue_context' => 'initial',
                'items' => $this->bothItems(),
                'idempotency_key' => 'oos-multi',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(0, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
        $this->assertSame(0, InventoryStockMovement::count());
    }

    public function test_n_front_desk_cannot_issue_without_an_active_register(): void
    {
        $booking = $this->checkedInStay();
        ShiftSession::query()->update(['ended_at' => now()]);
        $this->assertNull(ShiftService::activeRegister());

        $response = $this->actingAs($this->desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => $this->bothItems(),
        ]);
        $this->assertTrue($response->isForbidden() || $response->isRedirect());
        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());

        try {
            app(AmenityIssuanceService::class)->issue(
                $this->desk,
                $booking,
                $this->bothItems(),
                'initial',
                null,
                'no-register-direct'
            );
            $this->fail('Front Desk issuance without a register must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame(
                'An active Front Desk register is required to issue complimentary inventory.',
                $e->getMessage()
            );
        }

        $this->assertSame(20, (int) $this->safeguard->fresh()->current_stock);
    }

    public function test_o_admin_issuing_while_front_desk_register_is_active_stamps_admin_actor_and_fd_register(): void
    {
        $booking = $this->checkedInStay();
        $register = ShiftService::activeRegister();
        $this->assertSame($this->desk->id, (int) $register->user_id);

        $this->actingAs($this->admin)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => $this->bothItems(),
            'idempotency_key' => 'admin-while-fd-register',
        ])->assertRedirect();

        $issue = InventoryAmenityIssue::firstOrFail();
        $this->assertSame($this->admin->id, (int) $issue->issued_by);
        $this->assertSame($register->id, (int) $issue->shift_session_id);

        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame($this->admin->id, (int) $movement->performed_by);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
    }

    public function test_p_housekeeping_cannot_issue_or_edit_policies(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $booking = $this->checkedInStay();

        $this->actingAs($housekeeper)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => $this->bothItems(),
        ])->assertForbidden();

        $this->actingAs($housekeeper)->get(route('settings.amenity_policies.index'))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('settings.amenity_policies.store'), [
            'stay_key' => StayAmenityPolicy::STAY_OVERNIGHT,
            'inventory_item_id' => $this->safeguard->id,
            'default_quantity' => 1,
            'is_active' => 1,
        ])->assertForbidden();

        $this->actingAs($this->desk)->get(route('settings.amenity_policies.index'))->assertForbidden();
    }

    public function test_q_inventory_history_shows_complimentary_who_what_when_room_and_context(): void
    {
        $booking = $this->checkedInStay();
        $this->issue($booking, 'initial', $this->bothItems());

        $this->actingAs($this->admin)
            ->get(route('inventory.index', ['tab' => 'history', 'history_type' => 'complimentary_amenity']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.data.0.type_key', InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY)
                ->where('history.data.0.issue_context', 'initial')
                ->where('history.data.0.issue_context_label', 'Initial')
                ->where('history.data.0.room_number', '05')
                ->where('history.data.0.booking_ref', $booking->booking_ref)
                ->where('history.data.0.performed_by_name', 'Maria Santos')
                ->has('history.data.0.issue_reference')
                ->has('history.data.0.occurred_at_manila')
                ->has('history.data.0.register_label')
            );
    }

    public function test_r_stay_details_keeps_complimentary_history_separate_from_commercial_charges(): void
    {
        $booking = $this->checkedInStay();
        $this->issue($booking, 'initial', $this->bothItems());

        $payload = $this->actingAs($this->desk)
            ->getJson(route('bookings.show', ['booking' => $booking->id, 'json' => 1]))
            ->assertOk()
            ->json();

        $this->assertSame('initial', $payload['amenityIssuance']['issues'][0]['issue_context']);
        $this->assertSame([], $payload['inventoryUsages']);
        $this->assertEquals(0, $payload['calculations']['unpaid_inventory']);
        $this->assertEquals(0, $payload['calculations']['inventory_charges']);
    }

    public function test_s_and_t_checkout_due_and_minibar_expected_cash_are_unchanged(): void
    {
        $booking = $this->checkedInStay();
        $register = ShiftService::activeRegister();
        $settlement = app(InventoryUsageSettlementService::class);
        $cash = app(ShiftCashReconciliationService::class);
        $beforeDue = $settlement->unsettledTotal($booking->id);
        $beforeMinibar = $cash->liveForShift($register)['minibar']['expected_cash'];

        $this->issue($booking, 'initial', $this->bothItems());

        $this->assertSame($beforeDue, $settlement->unsettledTotal($booking->id));
        $this->assertSame($beforeMinibar, $cash->liveForShift($register->fresh())['minibar']['expected_cash']);
        $this->assertSame(0, InventoryUsage::count());
    }

    public function test_u_current_stock_cannot_go_negative(): void
    {
        $booking = $this->checkedInStay();
        $this->safeguard->update(['current_stock' => 0]);

        $this->actingAs($this->desk)
            ->post(route('bookings.amenities.issue', $booking), [
                'issue_context' => 'initial',
                'items' => [['inventory_item_id' => $this->safeguard->id, 'quantity' => 1]],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
    }

    public function test_v_idempotent_retry_does_not_double_deduct(): void
    {
        $booking = $this->checkedInStay();
        $payload = [
            'issue_context' => 'initial',
            'items' => $this->bothItems(),
            'idempotency_key' => 'same-checkin-issue',
        ];

        $this->actingAs($this->desk)->post(route('bookings.amenities.issue', $booking), $payload)->assertRedirect();
        $this->actingAs($this->desk)->post(route('bookings.amenities.issue', $booking), $payload)->assertRedirect();

        $this->assertSame(1, InventoryAmenityIssue::count());
        $this->assertSame(19, (int) $this->safeguard->fresh()->current_stock);
        $this->assertSame(19, (int) $this->shampoo->fresh()->current_stock);
        $this->assertSame(2, InventoryStockMovement::count());
    }

    public function test_admin_can_configure_policy_by_inventory_item_id_not_name(): void
    {
        $extra = $this->item('Toothpaste', 10);
        $this->actingAs($this->admin)->post(route('settings.amenity_policies.store'), [
            'stay_key' => StayAmenityPolicy::STAY_OVERNIGHT,
            'inventory_item_id' => $extra->id,
            'default_quantity' => 1,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('stay_amenity_policies', [
            'stay_key' => StayAmenityPolicy::STAY_OVERNIGHT,
            'inventory_item_id' => $extra->id,
            'default_quantity' => 1,
            'is_active' => 1,
        ]);
    }

    private function configurePolicies(): void
    {
        foreach ([StayAmenityPolicy::STAY_OVERNIGHT, StayAmenityPolicy::STAY_SHORT_TIME_24] as $stayKey) {
            StayAmenityPolicy::create([
                'stay_key' => $stayKey,
                'inventory_item_id' => $this->safeguard->id,
                'default_quantity' => 1,
                'is_active' => true,
            ]);
            StayAmenityPolicy::create([
                'stay_key' => $stayKey,
                'inventory_item_id' => $this->shampoo->id,
                'default_quantity' => 1,
                'is_active' => true,
            ]);
        }
    }

    private function acceptTrackedOpening(User $user, array $pairs): void
    {
        $shift = ShiftService::activeRegister();
        $this->assertNotNull($shift);
        $service = app(InventoryTurnoverService::class);
        $turnover = $service->ensureForShift($shift);
        $service->acceptOpening($user, $turnover, collect($pairs)->map(fn ($pair) => [
            'inventory_item_id' => $pair[0]->id,
            'quantity' => $pair[1],
        ])->all());
    }

    private function item(string $name, int $stock): InventoryItem
    {
        return InventoryItem::create([
            'item_name' => $name,
            'category' => 'toiletries',
            'unit' => 'piece',
            'current_stock' => $stock,
            'minimum_stock' => 2,
            'unit_cost' => 15,
            'selling_price' => 0,
            'is_active' => true,
            'is_turnover_tracked' => true,
        ]);
    }

    private function bothItems(): array
    {
        return [
            ['inventory_item_id' => $this->safeguard->id, 'quantity' => 1],
            ['inventory_item_id' => $this->shampoo->id, 'quantity' => 1],
        ];
    }

    private function walkInCheckIn(array $overrides = []): void
    {
        $payload = array_merge([
            'room_ids' => [$this->room->id],
            'guest_name' => 'Phase2 Guest',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'short_time_hours' => 3,
            'discount_type' => 'none',
            'payment_method' => 'cash',
            'cash_received' => 20000,
            'check_in' => '2026-08-20T15:00',
        ], $overrides);

        $this->actingAs($this->desk)
            ->post(route('checkin.store'), $payload)
            ->assertRedirect(route('checkin.index'));
    }

    private function checkedInStay(): Booking
    {
        $this->walkInCheckIn([
            'booking_type' => 'overnight',
            'num_nights' => 4,
            'issue_amenities' => 0,
        ]);

        return Booking::firstOrFail();
    }

    private function issue(Booking $booking, string $context, array $items, ?string $key = null): void
    {
        $this->actingAs($this->desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => $context,
            'items' => $items,
            'idempotency_key' => $key ?: $context.'-'.$booking->id.'-'.uniqid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
