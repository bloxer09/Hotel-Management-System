<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryChangeRequestException;
use App\Models\Booking;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use App\Services\BookingService;
use App\Services\InventoryChangeRequestService;
use App\Services\InventoryTurnoverService;
use App\Services\ShiftService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Shuchkin\SimpleXLSXGen;

class InventoryController extends Controller
{
    public function __construct(private InventoryChangeRequestService $changeRequests) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $tab = $request->input('tab', 'items');
        if (! in_array($tab, ['items', 'pending', 'history'], true)) {
            $tab = 'items';
        }

        $search = $request->input('search');
        $category = $request->input('category');
        $sortBy = $request->input('sort_by', 'item_name');
        $sortDir = $request->input('sort_dir', 'asc');

        $allowedSorts = ['item_name', 'category', 'current_stock', 'selling_price'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'item_name';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $pendingQuery = InventoryChangeRequest::query()
            ->where('status', InventoryChangeRequest::STATUS_PENDING);
        if ($user->role !== 'admin') {
            $pendingQuery->where('requested_by', $user->id);
        }
        $pendingCount = (clone $pendingQuery)->count();

        $payload = [
            'tab' => $tab,
            'pendingCount' => $pendingCount,
            'items' => ['data' => []],
            'pendingRequests' => ['data' => []],
            'history' => ['data' => []],
            'historyItems' => [],
            'historyUsers' => [],
            'activeBookings' => [],
            'currentSearch' => $search,
            'currentCategory' => $category,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'historyFilters' => $request->only([
                'history_item', 'history_search', 'history_type', 'history_status', 'history_user', 'history_from', 'history_to',
            ]),
            'trackedCount' => InventoryItem::query()->where('is_turnover_tracked', true)->where('is_active', true)->count(),
        ];

        if ($tab === 'items') {
            $payload['items'] = InventoryItem::orderBy($sortBy, $sortDir)
                ->when($search, fn ($query, $search) => $query->where('item_name', 'like', "%{$search}%"))
                ->when($category, fn ($query, $category) => $query->where('category', $category))
                ->paginate(15, ['*'], 'items_page')
                ->withQueryString();

            $payload['activeBookings'] = Booking::with(['room'])
                ->where('status', 'active')
                ->orderBy('guest_name', 'asc')
                ->get();
        }

        if ($tab === 'pending') {
            $payload['pendingRequests'] = $this->pendingRequestsPayload($user);
        }

        if ($tab === 'history') {
            $payload['history'] = $this->historyPayload($request, $user);
            $payload['historyItems'] = InventoryItem::withTrashed()
                ->orderBy('item_name')
                ->get(['id', 'item_name']);
            if ($user->role === 'admin') {
                $payload['historyUsers'] = User::query()
                    ->whereIn('role', ['admin', 'front_desk'])
                    ->orderBy('full_name')
                    ->get(['id', 'full_name']);
            }
        }

        return Inertia::render('Inventory/Index', $payload);
    }

    public function store(Request $request)
    {
        $request->validate([
            'item_name' => 'required|string|max:100',
            'category' => 'required|in:minibar,toiletries,laundry,amenities,supplies',
            'unit' => 'required|string|max:20',
            'current_stock' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'is_turnover_tracked' => 'sometimes|boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $user = $request->user();
        $data = $request->only([
            'item_name',
            'category',
            'unit',
            'current_stock',
            'minimum_stock',
            'unit_cost',
            'selling_price',
        ]);
        if ($user->role === 'admin') {
            $data['is_turnover_tracked'] = $request->boolean('is_turnover_tracked');
        }

        try {
            if ($user->role === 'admin') {
                $item = $this->changeRequests->createItemImmediately($user, $data, $request->file('image'));

                return back()->with('success', "Item {$item->item_name} created successfully.");
            }

            if ($user->role !== 'front_desk') {
                abort(403, 'Only administrators can add new inventory items.');
            }

            $this->changeRequests->submitCreateItemRequest($user, $data, $request->file('image'));

            return back()->with('success', 'New inventory item submitted for admin approval.');
        } catch (InventoryChangeRequestException $e) {
            return back()->withErrors(['item_name' => $e->getMessage()])->with('error', $e->getMessage());
        }
    }

    public function update(InventoryItem $inventoryItem, Request $request)
    {
        $request->validate([
            'item_name' => 'required|string|max:100',
            'category' => 'required|in:minibar,toiletries,laundry,amenities,supplies',
            'unit' => 'required|string|max:20',
            'minimum_stock' => 'required|integer|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'is_active' => 'required|boolean',
            'is_turnover_tracked' => 'sometimes|boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can update inventory details.');
        }

        $oldDetails = $inventoryItem->toArray();

        $data = $request->only([
            'item_name',
            'category',
            'unit',
            'minimum_stock',
            'unit_cost',
            'selling_price',
            'is_active',
            'is_turnover_tracked',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        if ($request->exists('is_turnover_tracked')) {
            $data['is_turnover_tracked'] = $request->boolean('is_turnover_tracked');
        } else {
            unset($data['is_turnover_tracked']);
        }

        try {
            $inventoryItem = $this->changeRequests->updateCatalogItem(
                $inventoryItem,
                $data,
                $request->hasFile('image') ? $request->file('image') : null
            );
        } catch (InventoryChangeRequestException $e) {
            return back()->withErrors(['item_name' => $e->getMessage()])->with('error', $e->getMessage());
        }

        $newDetails = $inventoryItem->toArray();

        BookingService::auditLog(
            $user->id,
            'INVENTORY_UPDATE',
            'inventory_items',
            $inventoryItem->id,
            $oldDetails,
            $newDetails,
            "Updated inventory details for {$inventoryItem->item_name}."
        );

        return back()->with('success', "Item {$inventoryItem->item_name} updated successfully.");
    }

    public function destroy(InventoryItem $inventoryItem, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can delete inventory items.');
        }

        $inventoryItem->delete();

        BookingService::auditLog(
            $user->id,
            'INVENTORY_DELETE',
            'inventory_items',
            $inventoryItem->id,
            null,
            null,
            "Soft deleted inventory item {$inventoryItem->item_name}."
        );

        return back()->with('success', "Item {$inventoryItem->item_name} has been removed.");
    }

    public function adjust(InventoryItem $inventoryItem, Request $request)
    {
        $type = $request->input('adjustment_type');
        $min = in_array($type, ['add', 'subtract'], true) ? 1 : 0;

        $request->validate([
            'adjustment_type' => 'required|in:add,subtract,set',
            'quantity' => "required|integer|min:{$min}",
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();
        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'You do not have permissions to perform stock adjustments.');
        }

        ShiftService::assertCanChangeTrackedInventory($user);
        app(InventoryTurnoverService::class)->assertItemsMutable(
            $user,
            [$inventoryItem->id],
            $user->role === 'admin'
                ? InventoryTurnoverService::CONTEXT_ADMIN_ADJUST
                : InventoryTurnoverService::CONTEXT_SALE
        );

        try {
            if ($user->role === 'admin') {
                $item = $this->changeRequests->adjustItemImmediately(
                    $user,
                    $inventoryItem,
                    $request->adjustment_type,
                    (int) $request->quantity,
                    $request->reason
                );

                return back()->with('success', "Stock for {$item->item_name} adjusted. New level: {$item->current_stock}.");
            }

            $this->changeRequests->submitAdjustmentRequest(
                $user,
                $inventoryItem,
                $request->adjustment_type,
                (int) $request->quantity,
                $request->reason
            );

            return back()->with('success', 'Request submitted. Official stock will change only after Admin approval.');
        } catch (InventoryChangeRequestException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(Request $request, InventoryChangeRequest $inventoryChangeRequest)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can approve inventory requests.');
        }

        $request->validate([
            'review_note' => 'nullable|string|max:500',
        ]);

        try {
            $this->changeRequests->approve($user, $inventoryChangeRequest, $request->input('review_note'));

            return back()->with('success', 'Inventory request approved.');
        } catch (InventoryChangeRequestException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, InventoryChangeRequest $inventoryChangeRequest)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can reject inventory requests.');
        }

        $request->validate([
            'review_note' => 'required|string|max:500',
        ]);

        try {
            $this->changeRequests->reject($user, $inventoryChangeRequest, $request->input('review_note'));

            return back()->with('success', 'Inventory request rejected.');
        } catch (InventoryChangeRequestException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403);
        }

        $search = $request->input('search');
        $category = $request->input('category');

        $query = InventoryItem::orderBy('item_name', 'asc')
            ->when($search, function ($query, $search) {
                return $query->where('item_name', 'like', "%{$search}%");
            })
            ->when($category, function ($query, $category) {
                return $query->where('category', $category);
            });

        $items = $query->get();

        $rows = [];
        $rows[] = ['Hotel Management System — Inventory Stock Status Report'];
        $rows[] = ['Category:', $category ?: 'All', 'Search:', $search ?: 'None'];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['Item ID', 'Item Name', 'Category', 'Unit', 'Current Stock', 'Min. Stock Alert Limit', 'Unit Cost (₱)', 'Selling Price (₱)', 'Active Status', 'Total Cost Value (₱)', 'Total Retail Value (₱)'];

        $totalItems = 0;
        $totalCostVal = 0;
        $totalRetailVal = 0;

        foreach ($items as $item) {
            $costVal = $item->current_stock * $item->unit_cost;
            $retailVal = $item->current_stock * $item->selling_price;

            $rows[] = [
                $item->id,
                $item->item_name,
                ucfirst($item->category),
                $item->unit,
                $item->current_stock,
                $item->minimum_stock,
                $item->unit_cost,
                $item->selling_price,
                $item->is_active ? 'Active' : 'Inactive',
                $costVal,
                $retailVal,
            ];

            $totalItems += $item->current_stock;
            $totalCostVal += $costVal;
            $totalRetailVal += $retailVal;
        }

        $rows[] = [];
        $rows[] = ['Total Stocks Available:', $totalItems];
        $rows[] = ['Total Cost Valuation:', $totalCostVal];
        $rows[] = ['Total Retail Valuation:', $totalRetailVal];

        $filename = 'inventory_report_'.date('Y-m-d_H-i-s').'.xlsx';
        SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }

    private function pendingRequestsPayload(User $user)
    {
        $query = InventoryChangeRequest::query()
            ->with([
                'item:id,item_name,current_stock,unit,image_path',
                'requester:id,full_name',
                'reviewer:id,full_name',
            ])
            ->orderByDesc('created_at');

        if ($user->role === 'admin') {
            $query->where('status', InventoryChangeRequest::STATUS_PENDING);
        } else {
            $query->where('requested_by', $user->id);
        }

        return $query->paginate(15, ['*'], 'requests_page')
            ->withQueryString()
            ->through(function (InventoryChangeRequest $request) {
                $currentStock = $request->item?->current_stock;
                $projected = $request->isCreateItem()
                    ? (int) $request->quantity
                    : ($currentStock === null ? null : $this->changeRequests->projectedStock(
                        $request->request_type,
                        (int) $currentStock,
                        (int) $request->quantity
                    ));

                return [
                    'id' => $request->id,
                    'request_type' => $request->request_type,
                    'status' => $request->status,
                    'quantity' => $request->quantity,
                    'stock_at_request' => $request->stock_at_request,
                    'current_stock' => $currentStock,
                    'projected_stock' => $projected,
                    'stock_changed_since_request' => $request->item
                        && $request->stock_at_request !== null
                        && (int) $request->item->current_stock !== (int) $request->stock_at_request,
                    'reason' => $request->reason,
                    'payload' => $request->request_payload,
                    'pending_image_url' => $request->pending_image_path
                        ? '/storage/'.$request->pending_image_path
                        : null,
                    'item' => $request->item,
                    'item_name' => $request->displayItemName(),
                    'requester' => $request->requester,
                    'reviewer' => $request->reviewer,
                    'review_note' => $request->review_note,
                    'reviewed_at_manila' => $this->toManila($request->reviewed_at),
                    'requested_at' => $request->created_at?->toIso8601String(),
                    'requested_at_manila' => $this->toManila($request->created_at),
                ];
            });
    }

    private function historyPayload(Request $request, User $user)
    {
        $itemNameExpr = "COALESCE(i.item_name, JSON_UNQUOTE(JSON_EXTRACT(r.request_payload, '$.item_name')), 'Unknown item')";

        $movements = DB::table('inventory_stock_movements as m')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->leftJoin('inventory_change_requests as r', 'r.id', '=', 'm.inventory_change_request_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'r.requested_by')
            ->leftJoin('users as performer', 'performer.id', '=', 'm.performed_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->leftJoin('shift_sessions as ss', 'ss.id', '=', 'm.shift_session_id')
            ->leftJoin('inventory_amenity_issues as iai', function ($join) {
                $join->on('iai.id', '=', 'm.source_id')
                    ->where('m.source_type', '=', 'amenity_issue');
            })
            ->leftJoin('bookings as ab', 'ab.id', '=', 'iai.booking_id')
            ->leftJoin('rooms as ar', 'ar.id', '=', 'iai.room_id')
            ->selectRaw("
                'movement' as row_kind,
                m.id as row_id,
                m.created_at as occurred_at,
                m.inventory_item_id as inventory_item_id,
                {$itemNameExpr} as item_name,
                m.movement_type as type_key,
                m.quantity_change as quantity_change,
                NULL as requested_quantity,
                m.stock_before as stock_before,
                m.stock_after as stock_after,
                COALESCE(r.status, 'applied') as request_status,
                COALESCE(r.reason, m.notes) as reason,
                r.requested_by as requested_by,
                COALESCE(r.reviewed_by, m.performed_by) as actor_id,
                r.review_note as review_note,
                COALESCE(requester.full_name, performer.full_name) as requested_by_name,
                COALESCE(reviewer.full_name, performer.full_name) as actor_name,
                performer.full_name as performed_by_name,
                m.shift_session_id as shift_session_id,
                ss.shift_code as shift_code,
                iai.reference as issue_reference,
                iai.issue_context as issue_context,
                ar.room_number as room_number,
                ab.booking_ref as booking_ref
            ");

        $rejected = DB::table('inventory_change_requests as r')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'r.inventory_item_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'r.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->where('r.status', InventoryChangeRequest::STATUS_REJECTED)
            ->selectRaw("
                'request' as row_kind,
                r.id as row_id,
                COALESCE(r.reviewed_at, r.updated_at, r.created_at) as occurred_at,
                r.inventory_item_id as inventory_item_id,
                {$itemNameExpr} as item_name,
                r.request_type as type_key,
                0 as quantity_change,
                r.quantity as requested_quantity,
                r.stock_at_request as stock_before,
                NULL as stock_after,
                r.status as request_status,
                r.reason as reason,
                r.requested_by as requested_by,
                r.reviewed_by as actor_id,
                r.review_note as review_note,
                requester.full_name as requested_by_name,
                reviewer.full_name as actor_name,
                NULL as performed_by_name,
                NULL as shift_session_id,
                NULL as shift_code,
                NULL as issue_reference,
                NULL as issue_context,
                NULL as room_number,
                NULL as booking_ref
            ");

        if ($user->role !== 'admin') {
            $movements->where(function ($query) use ($user) {
                $query->where('m.performed_by', $user->id)
                    ->orWhere('r.requested_by', $user->id);
            });
            $rejected->where('r.requested_by', $user->id);
        } elseif ($request->filled('history_user')) {
            $userId = $request->integer('history_user');
            $movements->where(function ($query) use ($userId) {
                $query->where('m.performed_by', $userId)
                    ->orWhere('r.requested_by', $userId)
                    ->orWhere('r.reviewed_by', $userId);
            });
            $rejected->where(function ($query) use ($userId) {
                $query->where('r.requested_by', $userId)
                    ->orWhere('r.reviewed_by', $userId);
            });
        }

        if ($request->filled('history_item')) {
            $itemId = $request->integer('history_item');
            $catalogItem = InventoryItem::withTrashed()->find($itemId);
            $itemName = $catalogItem?->item_name;

            $movements->where('m.inventory_item_id', $itemId);
            $rejected->where(function ($query) use ($itemId, $itemName) {
                $query->where('r.inventory_item_id', $itemId);
                if ($itemName) {
                    $query->orWhereRaw(
                        "LOWER(JSON_UNQUOTE(JSON_EXTRACT(r.request_payload, '$.item_name'))) = ?",
                        [mb_strtolower($itemName, 'UTF-8')]
                    );
                }
            });
        }

        if ($request->filled('history_search')) {
            $search = '%'.$request->input('history_search').'%';
            $movements->where(function ($query) use ($search) {
                $query->where('i.item_name', 'like', $search)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.request_payload, '$.item_name')) LIKE ?", [$search])
                    ->orWhere('iai.reference', 'like', $search)
                    ->orWhere('ar.room_number', 'like', $search)
                    ->orWhere('ab.booking_ref', 'like', $search);
            });
            $rejected->where(function ($query) use ($search) {
                $query->where('i.item_name', 'like', $search)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.request_payload, '$.item_name')) LIKE ?", [$search]);
            });
        }

        if ($request->filled('history_status')) {
            $status = $request->input('history_status');
            if ($status === 'applied') {
                $movements->whereNull('r.id');
                $rejected->whereRaw('1 = 0');
            } else {
                $movements->where('r.status', $status);
                $rejected->where('r.status', $status);
            }
        }

        $historyQuery = DB::query()
            ->fromSub($movements->unionAll($rejected), 'history_rows');

        if ($request->filled('history_type')) {
            $this->applyHistoryTypeFilter($historyQuery, (string) $request->input('history_type'));
        }

        if ($request->filled('history_from')) {
            $fromUtc = Carbon::createFromFormat('Y-m-d', $request->input('history_from'), 'Asia/Manila')
                ->startOfDay()
                ->utc();
            $historyQuery->where('occurred_at', '>=', $fromUtc);
        }
        if ($request->filled('history_to')) {
            $toUtc = Carbon::createFromFormat('Y-m-d', $request->input('history_to'), 'Asia/Manila')
                ->endOfDay()
                ->utc();
            $historyQuery->where('occurred_at', '<=', $toUtc);
        }

        return $historyQuery
            ->orderByDesc('occurred_at')
            ->orderByDesc('row_id')
            ->paginate(15, ['*'], 'history_page')
            ->withQueryString()
            ->through(function ($row) {
                $row->occurred_at_manila = HotelDateTime::formatUtcForDisplay($row->occurred_at);
                $row->requested_display = $this->requestedQuantityDisplay(
                    (string) $row->request_status,
                    (string) $row->type_key,
                    $row->requested_quantity !== null ? (int) $row->requested_quantity : null
                );
                $row->register_label = $row->shift_session_id
                    ? 'Shift #'.$row->shift_session_id
                    : 'No register';
                $row->issue_context_label = $row->issue_context === 'initial'
                    ? 'Initial'
                    : ($row->issue_context === 'refill' ? 'Refill' : null);

                return $row;
            });
    }

    private function applyHistoryTypeFilter($query, string $type): void
    {
        $mapping = [
            'add' => [
                'movement' => [InventoryStockMovement::TYPE_RESTOCK, InventoryStockMovement::TYPE_MANUAL_ADD],
                'request' => [InventoryChangeRequest::TYPE_ADD],
            ],
            'restock' => [
                'movement' => [InventoryStockMovement::TYPE_RESTOCK],
                'request' => [InventoryChangeRequest::TYPE_ADD],
            ],
            'subtract' => [
                'movement' => [InventoryStockMovement::TYPE_MANUAL_SUBTRACT],
                'request' => [InventoryChangeRequest::TYPE_SUBTRACT],
            ],
            'set' => [
                'movement' => [InventoryStockMovement::TYPE_MANUAL_SET],
                'request' => [InventoryChangeRequest::TYPE_SET],
            ],
            'create_item' => [
                'movement' => [InventoryStockMovement::TYPE_INITIAL_STOCK],
                'request' => [InventoryChangeRequest::TYPE_CREATE_ITEM],
            ],
            'pos_sale' => [
                'movement' => [InventoryStockMovement::TYPE_POS_SALE],
                'request' => [],
            ],
            'booking_usage' => [
                'movement' => [InventoryStockMovement::TYPE_BOOKING_USAGE],
                'request' => [],
            ],
            'booking_reversal' => [
                'movement' => [InventoryStockMovement::TYPE_BOOKING_REVERSAL],
                'request' => [],
            ],
            'complimentary_amenity' => [
                'movement' => [InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY],
                'request' => [],
            ],
            'inventory_variance' => [
                'movement' => [InventoryStockMovement::TYPE_INVENTORY_VARIANCE],
                'request' => [],
            ],
        ];

        if (! isset($mapping[$type])) {
            $query->whereRaw('1 = 0');

            return;
        }

        $movementTypes = $mapping[$type]['movement'];
        $requestTypes = $mapping[$type]['request'];

        $query->where(function ($inner) use ($movementTypes, $requestTypes) {
            if ($movementTypes !== []) {
                $inner->where(function ($movementQuery) use ($movementTypes) {
                    $movementQuery->where('row_kind', 'movement')
                        ->whereIn('type_key', $movementTypes);
                });
            }

            if ($requestTypes !== []) {
                $method = $movementTypes !== [] ? 'orWhere' : 'where';
                $inner->{$method}(function ($requestQuery) use ($requestTypes) {
                    $requestQuery->where('row_kind', 'request')
                        ->whereIn('type_key', $requestTypes);
                });
            }
        });
    }

    private function requestedQuantityDisplay(string $status, string $type, ?int $quantity): ?string
    {
        if ($status !== InventoryChangeRequest::STATUS_REJECTED || $quantity === null) {
            return null;
        }

        return match ($type) {
            InventoryChangeRequest::TYPE_SET => "Requested exact stock: {$quantity}",
            InventoryChangeRequest::TYPE_SUBTRACT => "Requested: -{$quantity}",
            InventoryChangeRequest::TYPE_ADD, InventoryChangeRequest::TYPE_CREATE_ITEM => "Requested: +{$quantity}",
            default => "Requested: {$quantity}",
        };
    }

    private function toManila(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)
            ->timezone('Asia/Manila')
            ->format('M d, Y g:i A');
    }
}
