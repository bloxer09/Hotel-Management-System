<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PosController extends Controller
{
    public function index(Request $request)
    {
        $items = InventoryItem::where('is_active', true)
            ->orderBy('item_name', 'asc')
            ->get();

        $activeBookings = \App\Models\Booking::with(['room'])
            ->where('status', 'active')
            ->orderBy('guest_name', 'asc')
            ->get();

        $transactions = \App\Models\Transaction::with(['processedBy', 'inventoryUsages.item'])
            ->where('transaction_type', 'pos_sale')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('POS/Index', [
            'items' => $items,
            'activeBookings' => $activeBookings,
            'transactions' => $transactions,
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'booking_id' => 'nullable|exists:bookings,id',
            'consumer_name' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'bank_amount' => 'nullable|numeric|min:0',
            'bank_ref' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $bookingId = $request->booking_id;
        $consumerName = $request->consumer_name;

        try {
            return \DB::transaction(function () use ($request, $user, $bookingId, $consumerName) {
                $grandTotal = 0;
                $usageCount = 0;
                $usedItemNames = [];

                $notes = $consumerName ? "Consumer: " . $consumerName : null;
                $usagesToCreate = [];

                foreach ($request->items as $lineItem) {
                    $item = InventoryItem::findOrFail($lineItem['item_id']);
                    $qty = (int)$lineItem['quantity'];

                    if ($item->current_stock < $qty) {
                        throw new \Exception("Insufficient stock for {$item->item_name}. Current: {$item->current_stock}, requested: {$qty}");
                    }

                    $oldStock = $item->current_stock;
                    $item->current_stock -= $qty;
                    $item->save();

                    $unitPrice = $item->selling_price;
                    $totalPrice = round($unitPrice * $qty, 2);

                    $usagesToCreate[] = [
                        'item_id' => $item->id,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ];

                    BookingService::auditLog(
                        $user->id,
                        'STOCK_DECREASE',
                        'inventory_items',
                        $item->id,
                        $oldStock,
                        $item->current_stock,
                        "POS Sale: Deducted {$qty} {$item->unit}(s) of {$item->item_name}." . ($bookingId ? " Charged to Booking ID {$bookingId}." : " Direct sale to Walk-in: {$consumerName}")
                    );

                    $grandTotal += $totalPrice;
                    $usageCount++;
                    $usedItemNames[] = "{$item->item_name} x{$qty}";
                }

                $transaction = \App\Models\Transaction::create([
                    'booking_id' => $bookingId,
                    'transaction_type' => 'pos_sale',
                    'description' => "POS Bulk Usage - " . implode(', ', $usedItemNames) . ($consumerName ? " (Consumer: {$consumerName})" : ""),
                    'amount' => $grandTotal,
                    'payment_method' => $request->payment_method,
                    'cash_amount' => $request->cash_amount ?: 0,
                    'gcash_amount' => $request->gcash_amount ?: 0,
                    'gcash_ref' => $request->gcash_ref,
                    'bank_amount' => $request->bank_amount ?: 0,
                    'bank_ref' => $request->bank_ref,
                    'processed_by' => $user->id,
                ]);

                foreach ($usagesToCreate as $u) {
                    \App\Models\InventoryUsage::create([
                        'booking_id' => $bookingId,
                        'transaction_id' => $transaction->id,
                        'item_id' => $u['item_id'],
                        'quantity' => $u['qty'],
                        'unit_price' => $u['unit_price'],
                        'total_price' => $u['total_price'],
                        'recorded_by' => $user->id,
                        'notes' => $notes,
                    ]);
                }

                $successMsg = "POS Sale recorded for {$usageCount} item(s)" . ($consumerName ? " for {$consumerName}" : "") . " - Total: ₱" . number_format($grandTotal, 2) . " (OR-{$transaction->or_number})";

                return back()->with([
                    'success' => $successMsg,
                    'new_pos_txn_id' => $transaction->id
                ]);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
