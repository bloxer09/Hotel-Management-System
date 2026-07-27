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

                $cashTendered = round((float) ($request->cash_amount ?: 0), 2);
                $gcashTendered = round((float) ($request->gcash_amount ?: 0), 2);
                $bankTendered = round((float) ($request->bank_amount ?: 0), 2);
                $cashCollected = $cashTendered;
                $gcashCollected = $gcashTendered;
                $bankCollected = $bankTendered;
                $changeGiven = 0.00;

                // A walk-in POS payment must record the sale amount that remains
                // in the drawer, never the cash tendered before change is given.
                // Room charges retain their existing deferred-payment behavior.
                if (! $bookingId) {
                    if ($request->payment_method === 'cash') {
                        if ($cashTendered + 0.01 < $grandTotal) {
                            throw new \InvalidArgumentException('Cash received is insufficient for this sale.');
                        }

                        $cashCollected = $grandTotal;
                        $gcashCollected = 0.00;
                        $bankCollected = 0.00;
                        $changeGiven = round($cashTendered - $cashCollected, 2);
                    } elseif ($request->payment_method === 'gcash') {
                        if (abs($gcashTendered - $grandTotal) > 0.01) {
                            throw new \InvalidArgumentException('GCash payment must equal the POS sale total.');
                        }

                        $cashCollected = 0.00;
                        $gcashCollected = $grandTotal;
                        $bankCollected = 0.00;
                    } elseif ($request->payment_method === 'bank_transfer') {
                        if (abs($bankTendered - $grandTotal) > 0.01) {
                            throw new \InvalidArgumentException('Bank transfer payment must equal the POS sale total.');
                        }

                        $cashCollected = 0.00;
                        $gcashCollected = 0.00;
                        $bankCollected = $grandTotal;
                    } else { // Split payment
                        $electronicTotal = round($gcashTendered + $bankTendered, 2);
                        if ($electronicTotal > $grandTotal + 0.01) {
                            throw new \InvalidArgumentException('Electronic split-payment amounts cannot exceed the POS sale total.');
                        }

                        $cashDue = round($grandTotal - $electronicTotal, 2);
                        if ($cashTendered + 0.01 < $cashDue) {
                            throw new \InvalidArgumentException('Cash received is insufficient for this split payment.');
                        }

                        $cashCollected = $cashDue;
                        $gcashCollected = $gcashTendered;
                        $bankCollected = $bankTendered;
                        $changeGiven = round($cashTendered - $cashCollected, 2);
                    }
                }

                $transactionNotes = $changeGiven > 0
                    ? 'Cash tendered: ₱'.number_format($cashTendered, 2)
                        .' | Change given: ₱'.number_format($changeGiven, 2)
                    : null;

                $transaction = \App\Models\Transaction::create([
                    'booking_id' => $bookingId,
                    'transaction_type' => 'pos_sale',
                    'description' => "POS Bulk Usage - " . implode(', ', $usedItemNames) . ($consumerName ? " (Consumer: {$consumerName})" : ""),
                    'amount' => $grandTotal,
                    'payment_method' => $request->payment_method,
                    'cash_amount' => $cashCollected,
                    'gcash_amount' => $gcashCollected,
                    'gcash_ref' => $request->gcash_ref,
                    'bank_amount' => $bankCollected,
                    'bank_ref' => $request->bank_ref,
                    'processed_by' => $user->id,
                    'notes' => $transactionNotes,
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

    public function export(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $query = \App\Models\InventoryUsage::with(['item', 'recorder', 'transaction', 'booking.room'])
            ->whereHas('transaction', function ($q) {
                $q->where('transaction_type', 'pos_sale');
            });

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $items = $query->orderBy('created_at', 'desc')->get();

        $rows = [];
        $rows[] = ['Hotel Management System — POS Sold Items Daily Report'];
        
        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['Date & Time', 'OR Number', 'Item Name', 'Quantity', 'Unit Price (₱)', 'Total Price (₱)', 'Payment Method', 'Recipient Detail', 'Processed By', 'Notes'];

        $totalRevenue = 0;
        $totalQty = 0;

        foreach ($items as $usage) {
            $txn = $usage->transaction;
            $payMethod = $txn ? $txn->payment_method : 'N/A';
            $orNumber = $txn ? $txn->or_number : 'N/A';
            
            $recipientDetail = 'Walk-in / Direct';
            if ($usage->booking_id) {
                $rNum = $usage->booking && $usage->booking->room ? $usage->booking->room->room_number : '?';
                $gName = $usage->booking ? $usage->booking->guest_name : '?';
                $recipientDetail = "Room {$rNum} / {$gName}";
            }

            $rows[] = [
                $usage->created_at->format('Y-m-d H:i:s'),
                $orNumber,
                $usage->item ? $usage->item->item_name : 'Deleted Item',
                $usage->quantity,
                $usage->unit_price,
                $usage->total_price,
                strtoupper($payMethod),
                $recipientDetail,
                $usage->recorder ? $usage->recorder->full_name : 'Unknown',
                $usage->notes
            ];
            $totalRevenue += $usage->total_price;
            $totalQty += $usage->quantity;
        }

        $rows[] = [];
        $rows[] = ['Total Sold Items:', $totalQty];
        $rows[] = ['Total POS Sales Revenue:', $totalRevenue];

        $filename = "pos_sold_items_" . date('Y-m-d_H-i-s') . ".xlsx";
        \Shuchkin\SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }
}
