<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $category = $request->input('category');

        $sortBy = $request->input('sort_by', 'item_name');
        $sortDir = $request->input('sort_dir', 'asc');

        $allowedSorts = ['item_name', 'category', 'current_stock', 'selling_price'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'item_name';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'asc';

        $items = InventoryItem::orderBy($sortBy, $sortDir)
            ->when($search, function ($query, $search) {
                return $query->where('item_name', 'like', "%{$search}%");
            })
            ->when($category, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->paginate(15)
            ->withQueryString();

        $activeBookings = \App\Models\Booking::with(['room'])
            ->where('status', 'active')
            ->orderBy('guest_name', 'asc')
            ->get();

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'activeBookings' => $activeBookings,
            'currentSearch' => $search,
            'currentCategory' => $category,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    // bulkUsageView removed

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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can add new inventory items.');
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $path = $file->store('inventory', 'public');
            $imagePath = '/storage/' . $path;
        }

        $data = $request->only([
            'item_name',
            'category',
            'unit',
            'current_stock',
            'minimum_stock',
            'unit_cost',
            'selling_price',
        ]);
        if ($imagePath) {
            $data['image_path'] = $imagePath;
        }

        $item = InventoryItem::create($data);

        BookingService::auditLog(
            $user->id,
            'INVENTORY_CREATE',
            'inventory_items',
            $item->id,
            null,
            $item->item_name,
            "Created new inventory item {$item->item_name} with initial stock {$item->current_stock}."
        );

        return back()->with('success', "Item {$item->item_name} created successfully.");
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
        ]);

        if ($request->hasFile('image')) {
            if ($inventoryItem->image_path) {
                $oldFile = str_replace('/storage/', '', $inventoryItem->image_path);
                \Storage::disk('public')->delete($oldFile);
            }
            $file = $request->file('image');
            $path = $file->store('inventory', 'public');
            $data['image_path'] = '/storage/' . $path;
        }

        $inventoryItem->update($data);
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
        $request->validate([
            'adjustment_type' => 'required|in:add,subtract,set',
            'quantity' => 'required|integer|min:0',
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'You do not have permissions to perform stock adjustments.');
        }

        $oldStock = $inventoryItem->current_stock;
        $qty = (int)$request->quantity;

        if ($request->adjustment_type === 'add') {
            $inventoryItem->current_stock += $qty;
        } elseif ($request->adjustment_type === 'subtract') {
            if ($inventoryItem->current_stock < $qty) {
                return back()->with('error', "Insufficient stock. Current: {$inventoryItem->current_stock}, attempted reduction: {$qty}");
            }
            $inventoryItem->current_stock -= $qty;
        } else { // set
            $inventoryItem->current_stock = $qty;
        }

        $inventoryItem->save();

        BookingService::auditLog(
            $user->id,
            'STOCK_ADJUSTMENT',
            'inventory_items',
            $inventoryItem->id,
            $oldStock,
            $inventoryItem->current_stock,
            "Stock adjusted ({$request->adjustment_type} to {$qty}). Reason: " . $request->reason
        );

        return back()->with('success', "Stock for {$inventoryItem->item_name} adjusted. New level: {$inventoryItem->current_stock}.");
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk'], true)) {
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
                $retailVal
            ];

            $totalItems += $item->current_stock;
            $totalCostVal += $costVal;
            $totalRetailVal += $retailVal;
        }

        $rows[] = [];
        $rows[] = ['Total Stocks Available:', $totalItems];
        $rows[] = ['Total Cost Valuation:', $totalCostVal];
        $rows[] = ['Total Retail Valuation:', $totalRetailVal];

        $filename = "inventory_report_" . date('Y-m-d_H-i-s') . ".xlsx";
        \Shuchkin\SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }

    // useItems removed
}
