<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'full_name' => 'Admin User']);
    }

    private function frontDesk(string $username = 'frontdesk_inv'): User
    {
        $user = User::factory()->create([
            'role' => 'front_desk',
            'username' => $username,
            'full_name' => 'Front Desk '.$username,
        ]);

        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);

        return $user;
    }

    private function item(array $overrides = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'item_name' => 'Mineral Water',
            'category' => 'minibar',
            'unit' => 'bottle',
            'current_stock' => 10,
            'minimum_stock' => 2,
            'unit_cost' => 15,
            'selling_price' => 35,
            'is_active' => true,
        ], $overrides));
    }

    private function createActiveBooking(User $user): Booking
    {
        $roomType = RoomType::create([
            'type_name' => 'Inventory Test Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'INV-'.$roomType->id,
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'INV-BOOK-'.$room->id,
            'room_id' => $room->id,
            'guest_name' => 'Inventory Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'check_in' => now()->subHour(),
            'expected_check_out' => now()->addHours(10),
            'status' => 'active',
            'payment_status' => 'unpaid',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'checked_in_by' => $user->id,
        ]);
    }

    public function test_front_desk_can_submit_a_new_item_request(): void
    {
        $desk = $this->frontDesk();

        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'New Snack Pack',
            'category' => 'minibar',
            'unit' => 'pack',
            'current_stock' => 12,
            'minimum_stock' => 3,
            'unit_cost' => 20,
            'selling_price' => 45,
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_change_requests', [
            'request_type' => InventoryChangeRequest::TYPE_CREATE_ITEM,
            'status' => InventoryChangeRequest::STATUS_PENDING,
            'requested_by' => $desk->id,
            'quantity' => 12,
        ]);
        $this->assertDatabaseMissing('inventory_items', ['item_name' => 'New Snack Pack']);
    }

    public function test_pending_new_item_is_unavailable_to_inventory_and_pos(): void
    {
        $desk = $this->frontDesk();
        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Hidden Pending Item',
            'category' => 'supplies',
            'unit' => 'box',
            'current_stock' => 5,
            'minimum_stock' => 1,
            'unit_cost' => 10,
            'selling_price' => 0,
        ]);

        $this->assertSame(0, InventoryItem::where('item_name', 'Hidden Pending Item')->count());

        $this->actingAs($desk)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingCount', 1)
                ->missing('items.data.0.item_name')
            );

        $cashier = User::factory()->create(['role' => 'cashier']);
        ShiftSession::create([
            'user_id' => $cashier->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);
        $this->actingAs($cashier)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($items) => collect($items)->every(fn ($item) => $item['item_name'] !== 'Hidden Pending Item'))
            );
    }

    public function test_admin_approval_creates_item_and_initial_stock_movement_once(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Approved Towel',
            'category' => 'amenities',
            'unit' => 'pc',
            'current_stock' => 8,
            'minimum_stock' => 2,
            'unit_cost' => 5,
            'selling_price' => 50,
        ]);
        $changeRequest = InventoryChangeRequest::first();

        $this->actingAs($admin)->post(route('inventory.requests.approve', $changeRequest))->assertRedirect();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $changeRequest))->assertRedirect();

        $this->assertSame(1, InventoryItem::where('item_name', 'Approved Towel')->count());
        $item = InventoryItem::where('item_name', 'Approved Towel')->first();
        $this->assertSame(8, $item->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('inventory_item_id', $item->id)->where('movement_type', InventoryStockMovement::TYPE_INITIAL_STOCK)->count());
        $this->assertSame(InventoryChangeRequest::STATUS_APPROVED, $changeRequest->fresh()->status);
    }

    public function test_rejection_does_not_create_an_item_and_requires_a_reason(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Rejected Soap',
            'category' => 'toiletries',
            'unit' => 'pc',
            'current_stock' => 4,
            'minimum_stock' => 1,
            'unit_cost' => 8,
            'selling_price' => 0,
        ]);
        $changeRequest = InventoryChangeRequest::first();

        $this->actingAs($admin)->post(route('inventory.requests.reject', $changeRequest), [])
            ->assertSessionHasErrors('review_note');
        $this->assertDatabaseMissing('inventory_items', ['item_name' => 'Rejected Soap']);

        $this->actingAs($admin)->post(route('inventory.requests.reject', $changeRequest), [
            'review_note' => 'Duplicate of existing soap',
        ])->assertRedirect();

        $this->assertDatabaseMissing('inventory_items', ['item_name' => 'Rejected Soap']);
        $this->assertSame(InventoryChangeRequest::STATUS_REJECTED, $changeRequest->fresh()->status);
        $this->assertSame(0, InventoryStockMovement::count());
    }

    public function test_front_desk_adjustments_do_not_change_stock_until_approved(): void
    {
        $desk = $this->frontDesk();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 5,
            'reason' => 'Delivery arrived',
        ])->assertRedirect();

        $this->assertSame(10, $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_change_requests', [
            'inventory_item_id' => $item->id,
            'request_type' => 'add',
            'status' => InventoryChangeRequest::STATUS_PENDING,
            'stock_at_request' => 10,
        ]);
    }

    public function test_admin_approval_applies_add_subtract_and_set_adjustments(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 4,
            'reason' => 'Restock',
        ]);
        $add = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $add));
        $this->assertSame(14, $item->fresh()->current_stock);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'subtract',
            'quantity' => 3,
            'reason' => 'Spoilage',
        ]);
        $subtract = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $subtract));
        $this->assertSame(11, $item->fresh()->current_stock);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'set',
            'quantity' => 20,
            'reason' => 'Physical count',
        ]);
        $set = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $set));
        $this->assertSame(20, $item->fresh()->current_stock);
    }

    public function test_insufficient_stock_subtraction_cannot_be_approved(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 2]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'subtract',
            'quantity' => 2,
            'reason' => 'Use remaining',
        ]);
        $request = InventoryChangeRequest::first();
        $item->update(['current_stock' => 1]);

        $this->actingAs($admin)->from(route('inventory.index', ['tab' => 'pending']))
            ->post(route('inventory.requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $item->fresh()->current_stock);
        $this->assertSame(InventoryChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_stale_set_exact_request_cannot_be_approved(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'set',
            'quantity' => 7,
            'reason' => 'Count',
        ]);
        $request = InventoryChangeRequest::first();
        $item->update(['current_stock' => 12]);

        $this->actingAs($admin)->from(route('inventory.index', ['tab' => 'pending']))
            ->post(route('inventory.requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(12, $item->fresh()->current_stock);
        $this->assertSame(InventoryChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_double_approval_cannot_change_stock_twice(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 5,
            'reason' => 'Delivery',
        ]);
        $request = InventoryChangeRequest::first();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $request));
        $this->actingAs($admin)->post(route('inventory.requests.approve', $request));

        $this->assertSame(15, $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('inventory_change_request_id', $request->id)->count());
    }

    public function test_non_admin_cannot_approve_or_reject(): void
    {
        $desk = $this->frontDesk();
        $item = $this->item();
        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 1,
            'reason' => 'Need more',
        ]);
        $request = InventoryChangeRequest::first();

        $this->actingAs($desk)->post(route('inventory.requests.approve', $request))->assertForbidden();
        $this->actingAs($desk)->post(route('inventory.requests.reject', $request), [
            'review_note' => 'No',
        ])->assertForbidden();

        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->post(route('inventory.requests.approve', $request))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('inventory.requests.reject', $request), [
            'review_note' => 'No',
        ])->assertForbidden();
    }

    public function test_front_desk_cannot_see_another_users_requests(): void
    {
        $deskA = $this->frontDesk('desk_a');
        $deskB = $this->frontDesk('desk_b');
        $item = $this->item();

        $this->actingAs($deskA)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'A request',
        ]);

        $this->actingAs($deskB)->get(route('inventory.index', ['tab' => 'pending']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingRequests.data', 0)
                ->where('pendingCount', 0)
            );

        $this->actingAs($deskA)->get(route('inventory.index', ['tab' => 'pending']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingRequests.data', 1)
                ->where('pendingCount', 1)
            );
    }

    public function test_admin_immediate_actions_create_approved_request_and_movement(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('inventory.store'), [
            'item_name' => 'Admin Created Item',
            'category' => 'laundry',
            'unit' => 'kg',
            'current_stock' => 6,
            'minimum_stock' => 1,
            'unit_cost' => 0,
            'selling_price' => 80,
        ])->assertRedirect();

        $item = InventoryItem::where('item_name', 'Admin Created Item')->first();
        $this->assertNotNull($item);
        $this->assertDatabaseHas('inventory_change_requests', [
            'inventory_item_id' => $item->id,
            'status' => InventoryChangeRequest::STATUS_APPROVED,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_INITIAL_STOCK,
            'stock_after' => 6,
        ]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'Admin restock',
        ]);
        $this->assertSame(8, $item->fresh()->current_stock);
        $this->assertSame(2, InventoryStockMovement::where('inventory_item_id', $item->id)->count());
    }

    public function test_pos_sale_creates_a_stock_movement(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        ShiftSession::create([
            'user_id' => $cashier->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 500,
            'opening_cash_minibar' => 500,
        ]);
        $item = $this->item(['current_stock' => 10, 'selling_price' => 35]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'cash_amount' => 70,
        ])->assertSessionHasNoErrors();

        $this->assertSame(8, $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -2,
            'stock_before' => 10,
            'stock_after' => 8,
        ]);
    }

    public function test_booking_usage_and_reversal_create_matching_movements(): void
    {
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);
        $booking = $this->createActiveBooking($admin);

        $this->actingAs($admin)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 3,
        ])->assertRedirect();

        $this->assertSame(7, $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'movement_type' => InventoryStockMovement::TYPE_BOOKING_USAGE,
            'quantity_change' => -3,
            'stock_after' => 7,
        ]);

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left',
        ])->assertRedirect();

        $this->assertSame(10, $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'movement_type' => InventoryStockMovement::TYPE_BOOKING_REVERSAL,
            'quantity_change' => 3,
            'stock_after' => 10,
        ]);
    }

    public function test_existing_inventory_index_filters_and_permissions_still_work(): void
    {
        $admin = $this->admin();
        $this->item(['item_name' => 'Cola Can', 'category' => 'minibar']);
        $this->item(['item_name' => 'Bath Soap', 'category' => 'toiletries']);

        $this->actingAs($admin)->get(route('inventory.index', ['search' => 'Cola', 'category' => 'minibar']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Index')
                ->has('items.data', 1)
                ->where('items.data.0.item_name', 'Cola Can')
                ->where('currentSearch', 'Cola')
                ->where('currentCategory', 'minibar')
            );

        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('inventory.store'), [
            'item_name' => 'Nope',
            'category' => 'minibar',
            'unit' => 'pc',
            'current_stock' => 1,
            'minimum_stock' => 0,
            'unit_cost' => 1,
            'selling_price' => 1,
        ])->assertForbidden();
    }
}
