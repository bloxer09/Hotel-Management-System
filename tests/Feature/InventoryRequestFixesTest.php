<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryUsage;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\InventoryChangeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryRequestFixesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'full_name' => 'Admin Reviewer']);
    }

    private function frontDesk(string $username = 'desk_fixes'): User
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

    private function cashier(): User
    {
        $user = User::factory()->create(['role' => 'front_desk', 'full_name' => 'POS Cashier']);
        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 500,
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

    private function fakeImage(string $filename = 'photo.png'): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );

        return UploadedFile::fake()->createWithContent($filename, $png);
    }

    private function createActiveBooking(User $user): Booking
    {
        $roomType = RoomType::create([
            'type_name' => 'Fix Test Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'FIX-'.$roomType->id,
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'FIX-BOOK-'.$room->id,
            'room_id' => $room->id,
            'guest_name' => 'Fix Guest',
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

    public function test_pos_combines_duplicate_item_ids_and_uses_locked_stock_levels(): void
    {
        $cashier = $this->cashier();
        $item = $this->item(['current_stock' => 10, 'selling_price' => 20]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 3],
                ['item_id' => $item->id, 'quantity' => 4],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 140,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $item->fresh()->current_stock);
        $this->assertSame(1, InventoryUsage::where('item_id', $item->id)->count());
        $this->assertDatabaseHas('inventory_stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -7,
            'stock_before' => 10,
            'stock_after' => 3,
        ]);
    }

    public function test_pos_cannot_oversell_when_combined_quantity_exceeds_stock(): void
    {
        $cashier = $this->cashier();
        $item = $this->item(['current_stock' => 5, 'selling_price' => 20]);

        $this->actingAs($cashier)->from(route('pos.index'))->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 3],
                ['item_id' => $item->id, 'quantity' => 3],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 120,
        ])->assertSessionHas('error');

        $this->assertSame(5, $item->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());
    }

    public function test_sequential_pos_sales_cannot_take_more_stock_than_available(): void
    {
        $cashier = $this->cashier();
        $item = $this->item(['current_stock' => 10, 'selling_price' => 10]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'consumer_name' => 'First',
            'items' => [['item_id' => $item->id, 'quantity' => 6]],
            'payment_method' => 'cash',
            'cash_amount' => 60,
        ])->assertSessionHasNoErrors();

        $this->actingAs($cashier)->from(route('pos.index'))->post(route('pos.checkout'), [
            'consumer_name' => 'Second',
            'items' => [['item_id' => $item->id, 'quantity' => 6]],
            'payment_method' => 'cash',
            'cash_amount' => 60,
        ])->assertSessionHas('error');

        $this->assertSame(4, $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_POS_SALE)->count());
    }

    public function test_booking_usage_checks_stock_after_locking_and_cannot_oversell(): void
    {
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);
        $booking = $this->createActiveBooking($admin);

        $this->actingAs($admin)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 6,
        ])->assertRedirect();

        $this->actingAs($admin)->from(route('bookings.show', $booking))->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 6,
        ])->assertSessionHas('error');

        $this->assertSame(4, $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_BOOKING_USAGE)->count());
    }

    public function test_booking_cancellation_cannot_restore_stock_twice(): void
    {
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);
        $booking = $this->createActiveBooking($admin);

        $this->actingAs($admin)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 3,
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left',
        ])->assertRedirect();
        $this->assertSame(10, $item->fresh()->current_stock);

        $this->actingAs($admin)->from(route('rooms.index'))->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left again',
        ])->assertSessionHas('error');

        $this->assertSame(10, $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_BOOKING_REVERSAL)->count());
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_normalized_item_names_block_case_and_whitespace_duplicates(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('inventory.store'), [
            'item_name' => '  Bath   Soap ',
            'category' => 'toiletries',
            'unit' => 'pc',
            'current_stock' => 4,
            'minimum_stock' => 1,
            'unit_cost' => 8,
            'selling_price' => 20,
        ])->assertRedirect();

        $item = InventoryItem::first();
        $this->assertSame('Bath Soap', $item->item_name);
        $this->assertSame('bath soap', $item->normalized_name);

        $this->actingAs($admin)->from(route('inventory.index'))->post(route('inventory.store'), [
            'item_name' => 'bath soap',
            'category' => 'toiletries',
            'unit' => 'pc',
            'current_stock' => 2,
            'minimum_stock' => 1,
            'unit_cost' => 8,
            'selling_price' => 20,
        ])->assertSessionHas('error');

        $this->assertSame(1, InventoryItem::count());

        $other = $this->item(['item_name' => 'Cola Can', 'category' => 'minibar']);
        $this->actingAs($admin)->from(route('inventory.index'))->patch(route('inventory.update', $other), [
            'item_name' => 'BATH SOAP',
            'category' => 'toiletries',
            'unit' => 'can',
            'minimum_stock' => 1,
            'unit_cost' => 10,
            'selling_price' => 30,
            'is_active' => true,
        ])->assertSessionHas('error');

        $this->assertSame('Cola Can', $other->fresh()->item_name);
    }

    public function test_duplicate_create_item_approvals_cannot_insert_the_same_normalized_name(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();

        foreach (['Widget Pack', '  widget   pack '] as $name) {
            $this->actingAs($desk)->post(route('inventory.store'), [
                'item_name' => $name,
                'category' => 'minibar',
                'unit' => 'pack',
                'current_stock' => 5,
                'minimum_stock' => 1,
                'unit_cost' => 10,
                'selling_price' => 25,
            ]);
        }

        $requests = InventoryChangeRequest::orderBy('id')->get();
        $this->assertCount(2, $requests);

        $this->actingAs($admin)->post(route('inventory.requests.approve', $requests[0]))->assertRedirect();
        $this->actingAs($admin)->from(route('inventory.index', ['tab' => 'pending']))
            ->post(route('inventory.requests.approve', $requests[1]))
            ->assertSessionHas('error');

        $this->assertSame(1, InventoryItem::where('normalized_name', 'widget pack')->count());
        $this->assertSame(InventoryChangeRequest::STATUS_PENDING, $requests[1]->fresh()->status);
    }

    public function test_rejected_requests_expose_zero_quantity_change_and_requested_display(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item();

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 5,
            'reason' => 'Need more',
        ]);
        $add = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $add), [
            'review_note' => 'Not yet',
        ]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'subtract',
            'quantity' => 5,
            'reason' => 'Damaged',
        ]);
        $subtract = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $subtract), [
            'review_note' => 'Keep stock',
        ]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'set',
            'quantity' => 5,
            'reason' => 'Count',
        ]);
        $set = InventoryChangeRequest::latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $set), [
            'review_note' => 'Recount',
        ]);

        $this->actingAs($admin)->get(route('inventory.index', ['tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 3)
                ->where('history.data', function ($rows) {
                    $rows = collect($rows);
                    $this->assertTrue($rows->every(fn ($row) => (int) $row['quantity_change'] === 0));
                    $this->assertTrue($rows->contains(fn ($row) => $row['requested_display'] === 'Requested: +5'));
                    $this->assertTrue($rows->contains(fn ($row) => $row['requested_display'] === 'Requested: -5'));
                    $this->assertTrue($rows->contains(fn ($row) => $row['requested_display'] === 'Requested exact stock: 5'));

                    return true;
                })
            );
    }

    public function test_history_type_filters_map_approved_movements_and_rejected_requests(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'Admin add',
        ]);
        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 4,
            'reason' => 'Desk add',
        ]);
        $pendingAdd = InventoryChangeRequest::where('status', InventoryChangeRequest::STATUS_PENDING)->latest('id')->first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $pendingAdd), [
            'review_note' => 'Later',
        ]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'subtract',
            'quantity' => 1,
            'reason' => 'Admin subtract',
        ]);

        $this->actingAs($admin)->get(route('inventory.index', [
            'tab' => 'history',
            'history_type' => 'add',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 2)
            ->where('history.data', function ($rows) {
                $keys = collect($rows)->pluck('type_key')->sort()->values()->all();
                $this->assertSame(['add', 'manual_add'], $keys);

                return true;
            })
        );

        $this->actingAs($admin)->get(route('inventory.index', [
            'tab' => 'history',
            'history_type' => 'subtract',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.type_key', 'manual_subtract')
        );
    }

    public function test_rejected_new_item_requests_are_found_by_item_name_search(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();

        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Uncreated Widget',
            'category' => 'supplies',
            'unit' => 'box',
            'current_stock' => 9,
            'minimum_stock' => 1,
            'unit_cost' => 12,
            'selling_price' => 0,
        ]);
        $request = InventoryChangeRequest::first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $request), [
            'review_note' => 'Not needed',
        ]);

        $this->actingAs($admin)->get(route('inventory.index', [
            'tab' => 'history',
            'history_search' => 'Uncreated Widget',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.item_name', 'Uncreated Widget')
            ->where('history.data.0.inventory_item_id', null)
            ->where('history.data.0.requested_display', 'Requested: +9')
        );
    }

    public function test_front_desk_cannot_access_another_users_history(): void
    {
        $deskA = $this->frontDesk('desk_hist_a');
        $deskB = $this->frontDesk('desk_hist_b');
        $admin = $this->admin();
        $item = $this->item();

        $this->actingAs($deskA)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'A only',
        ]);
        $request = InventoryChangeRequest::first();
        $this->actingAs($admin)->post(route('inventory.requests.reject', $request), [
            'review_note' => 'No',
        ]);

        $this->actingAs($deskB)->get(route('inventory.index', [
            'tab' => 'history',
            'history_user' => $deskA->id,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 0)
        );

        $this->actingAs($deskA)->get(route('inventory.index', ['tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 1)
                ->where('history.data.0.requested_by', $deskA->id)
            );
    }

    public function test_history_date_filters_use_manila_day_boundaries_converted_to_utc(): void
    {
        $admin = $this->admin();
        $item = $this->item();

        InventoryStockMovement::record([
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -1,
            'stock_before' => 10,
            'stock_after' => 9,
            'performed_by' => $admin->id,
            'notes' => 'Before midnight Manila',
            'created_at' => Carbon::parse('2026-08-16 15:59:59', 'UTC'),
        ]);
        InventoryStockMovement::record([
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -1,
            'stock_before' => 9,
            'stock_after' => 8,
            'performed_by' => $admin->id,
            'notes' => 'Midnight Manila',
            'created_at' => Carbon::parse('2026-08-16 16:00:00', 'UTC'),
        ]);

        $this->actingAs($admin)->get(route('inventory.index', [
            'tab' => 'history',
            'history_from' => '2026-08-16',
            'history_to' => '2026-08-16',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.stock_after', fn ($value) => (int) $value === 9)
        );

        $this->actingAs($admin)->get(route('inventory.index', [
            'tab' => 'history',
            'history_from' => '2026-08-17',
            'history_to' => '2026-08-17',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.stock_after', fn ($value) => (int) $value === 8)
        );
    }

    public function test_approval_note_is_optional_and_shown_in_history(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item();

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'Delivery',
        ]);
        $request = InventoryChangeRequest::first();

        $this->actingAs($admin)->post(route('inventory.requests.approve', $request), [
            'review_note' => 'Verified delivery receipt',
        ])->assertRedirect();

        $this->assertSame('Verified delivery receipt', $request->fresh()->review_note);
        $this->actingAs($admin)->get(route('inventory.index', ['tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.data.0.review_note', 'Verified delivery receipt')
            );
    }

    public function test_notification_count_uses_full_pending_query_above_twenty(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $item = $this->item();

        for ($i = 1; $i <= 21; $i++) {
            $this->actingAs($desk)->post(route('inventory.adjust', $item), [
                'adjustment_type' => 'add',
                'quantity' => 1,
                'reason' => "Delivery {$i}",
            ]);
        }

        $adminPayload = $this->actingAs($admin)->getJson(route('api.notifications'))->assertOk()->json();
        $this->assertSame(21, $adminPayload['counts']['inventory_requests']);
        $this->assertCount(20, collect($adminPayload['items'])->where('type', 'inventory_request')->values());

        $deskPayload = $this->actingAs($desk)->getJson(route('api.notifications'))->assertOk()->json();
        $this->assertSame(21, $deskPayload['counts']['inventory_requests']);

        $otherDesk = $this->frontDesk('desk_other_notif');
        $otherPayload = $this->actingAs($otherDesk)->getJson(route('api.notifications'))->assertOk()->json();
        $this->assertSame(0, $otherPayload['counts']['inventory_requests']);
        $this->assertCount(0, collect($otherPayload['items'])->where('type', 'inventory_request')->values());
    }

    public function test_request_image_stays_in_place_on_approval_and_is_removed_after_rejected_commit(): void
    {
        Storage::fake('public');
        $desk = $this->frontDesk();
        $admin = $this->admin();

        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Photo Item',
            'category' => 'amenities',
            'unit' => 'pc',
            'current_stock' => 3,
            'minimum_stock' => 1,
            'unit_cost' => 5,
            'selling_price' => 15,
            'image' => $this->fakeImage('photo.png'),
        ])->assertRedirect();

        $approveRequest = InventoryChangeRequest::first();
        $storedPath = $approveRequest->pending_image_path;
        $this->assertNotNull($storedPath);
        $this->assertStringStartsWith('inventory/requests/', $storedPath);
        Storage::disk('public')->assertExists($storedPath);

        $this->actingAs($admin)->post(route('inventory.requests.approve', $approveRequest))->assertRedirect();
        $item = InventoryItem::where('item_name', 'Photo Item')->first();
        $this->assertSame('/storage/'.$storedPath, $item->image_path);
        Storage::disk('public')->assertExists($storedPath);
        $this->assertNull($approveRequest->fresh()->pending_image_path);

        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Rejected Photo Item',
            'category' => 'amenities',
            'unit' => 'pc',
            'current_stock' => 2,
            'minimum_stock' => 1,
            'unit_cost' => 5,
            'selling_price' => 15,
            'image' => $this->fakeImage('reject.png'),
        ]);
        $rejectRequest = InventoryChangeRequest::whereNull('inventory_item_id')->latest('id')->first();
        $rejectPath = $rejectRequest->pending_image_path;
        Storage::disk('public')->assertExists($rejectPath);

        $this->actingAs($admin)->post(route('inventory.requests.reject', $rejectRequest), [
            'review_note' => 'Blurry photo',
        ])->assertRedirect();

        $this->assertSame(InventoryChangeRequest::STATUS_REJECTED, $rejectRequest->fresh()->status);
        Storage::disk('public')->assertMissing($rejectPath);
        $this->assertNull($rejectRequest->fresh()->pending_image_path);
    }

    public function test_existing_pending_image_path_is_referenced_without_moving_on_approval(): void
    {
        Storage::fake('public');
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $legacyPath = 'inventory/pending/legacy.jpg';
        Storage::disk('public')->put($legacyPath, 'image-bytes');

        $request = InventoryChangeRequest::create([
            'request_type' => InventoryChangeRequest::TYPE_CREATE_ITEM,
            'request_payload' => [
                'item_name' => 'Legacy Photo Item',
                'category' => 'supplies',
                'unit' => 'box',
                'current_stock' => 2,
                'minimum_stock' => 1,
                'unit_cost' => 4,
                'selling_price' => 0,
            ],
            'pending_image_path' => $legacyPath,
            'quantity' => 2,
            'status' => InventoryChangeRequest::STATUS_PENDING,
            'reason' => 'Legacy pending image',
            'requested_by' => $desk->id,
        ]);

        $this->actingAs($admin)->post(route('inventory.requests.approve', $request))->assertRedirect();

        $item = InventoryItem::where('item_name', 'Legacy Photo Item')->first();
        $this->assertSame('/storage/'.$legacyPath, $item->image_path);
        Storage::disk('public')->assertExists($legacyPath);
        Storage::disk('public')->assertMissing('inventory/legacy.jpg');
    }

    public function test_orphan_request_image_is_removed_when_create_fails_after_upload(): void
    {
        Storage::fake('public');
        $ghost = new User(['id' => 999999]);

        try {
            app(InventoryChangeRequestService::class)->submitCreateItemRequest($ghost, [
                'item_name' => 'Orphan Image Item',
                'category' => 'supplies',
                'unit' => 'box',
                'current_stock' => 1,
                'minimum_stock' => 0,
                'unit_cost' => 1,
                'selling_price' => 1,
            ], $this->fakeImage('orphan.png'));
            $this->fail('Expected request creation to fail.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, InventoryChangeRequest::count());
    }

    public function test_rejected_request_stays_rejected_if_image_cleanup_fails(): void
    {
        Storage::fake('public');
        Log::spy();
        $desk = $this->frontDesk();
        $admin = $this->admin();

        $this->actingAs($desk)->post(route('inventory.store'), [
            'item_name' => 'Cleanup Fail Item',
            'category' => 'laundry',
            'unit' => 'kg',
            'current_stock' => 1,
            'minimum_stock' => 0,
            'unit_cost' => 1,
            'selling_price' => 1,
            'image' => $this->fakeImage('fail.png'),
        ]);
        $request = InventoryChangeRequest::first();

        Storage::shouldReceive('disk')->with('public')->andReturn(
            tap(\Mockery::mock(), function ($mock) {
                $mock->shouldReceive('delete')->andThrow(new \RuntimeException('cleanup failed'));
            })
        );

        $this->actingAs($admin)->post(route('inventory.requests.reject', $request), [
            'review_note' => 'Not approved',
        ])->assertRedirect();

        $this->assertSame(InventoryChangeRequest::STATUS_REJECTED, $request->fresh()->status);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_duplicate_name_edit_with_new_image_keeps_the_original_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $originalPath = 'inventory/original.png';
        Storage::disk('public')->put($originalPath, 'original-bytes');

        $this->item(['item_name' => 'Existing Soap', 'category' => 'toiletries']);
        $item = $this->item([
            'item_name' => 'Towel Set',
            'category' => 'toiletries',
            'image_path' => '/storage/'.$originalPath,
        ]);

        $this->actingAs($admin)->from(route('inventory.index'))->patch(route('inventory.update', $item), [
            'item_name' => 'existing soap',
            'category' => 'toiletries',
            'unit' => 'pc',
            'minimum_stock' => 1,
            'unit_cost' => 8,
            'selling_price' => 20,
            'is_active' => true,
            'image' => $this->fakeImage('replacement.png'),
        ])->assertSessionHas('error');

        $fresh = $item->fresh();
        $this->assertSame('Towel Set', $fresh->item_name);
        $this->assertSame('/storage/'.$originalPath, $fresh->image_path);
        Storage::disk('public')->assertExists($originalPath);
        $this->assertSame([$originalPath], Storage::disk('public')->allFiles());
    }

    public function test_failed_catalog_edit_removes_the_unused_new_upload(): void
    {
        Storage::fake('public');
        $originalPath = 'inventory/keep.png';
        Storage::disk('public')->put($originalPath, 'keep-bytes');
        $item = $this->item([
            'item_name' => 'Keep Image Item',
            'category' => 'amenities',
            'image_path' => '/storage/'.$originalPath,
        ]);

        try {
            app(InventoryChangeRequestService::class)->updateCatalogItem($item, [
                'item_name' => 'Keep Image Item',
                'category' => 'not-a-category',
                'unit' => 'pc',
                'minimum_stock' => 1,
                'unit_cost' => 1,
                'selling_price' => 1,
                'is_active' => true,
            ], $this->fakeImage('unused.png'));
            $this->fail('Expected catalog update to fail.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame('/storage/'.$originalPath, $item->fresh()->image_path);
        Storage::disk('public')->assertExists($originalPath);
        $this->assertSame([$originalPath], Storage::disk('public')->allFiles());
    }

    public function test_successful_catalog_edit_stores_the_new_image_and_removes_the_old_one(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $oldPath = 'inventory/old-catalog.png';
        Storage::disk('public')->put($oldPath, 'old-bytes');
        $item = $this->item([
            'item_name' => 'Catalog Photo Item',
            'category' => 'supplies',
            'image_path' => '/storage/'.$oldPath,
        ]);

        $this->actingAs($admin)->patch(route('inventory.update', $item), [
            'item_name' => 'Catalog Photo Item',
            'category' => 'supplies',
            'unit' => 'box',
            'minimum_stock' => 2,
            'unit_cost' => 12,
            'selling_price' => 0,
            'is_active' => true,
            'image' => $this->fakeImage('new-catalog.png'),
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh->image_path);
        $this->assertNotSame('/storage/'.$oldPath, $fresh->image_path);
        $this->assertStringStartsWith('/storage/inventory/', $fresh->image_path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $fresh->image_path));
    }
}
