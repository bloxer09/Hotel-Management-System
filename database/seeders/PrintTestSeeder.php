<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Room;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\InventoryItem;
use App\Models\InventoryUsage;
use App\Models\ShiftSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PrintTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Find Maria Santos (or create her if she doesn't exist)
        $maria = User::where('full_name', 'Maria Santos')->first();
        if (!$maria) {
            $maria = User::create([
                'username' => 'frontdesk1',
                'password' => bcrypt('password'),
                'full_name' => 'Maria Santos',
                'role' => 'front_desk',
                'email' => 'maria@hotel.com',
                'phone' => '09172345678',
                'is_active' => true,
            ]);
        }

        // 2. Set up Shift Session #2 for Maria Santos
        $shift = ShiftSession::firstOrNew(['id' => 2]);
        $shift->user_id = $maria->id;
        $shift->shift_code = 'evening';
        $shift->started_at = now()->subHours(4); // Dynamic start time 4 hours ago
        $shift->ended_at = null; // keep active
        $shift->opening_cash = 2500.00;
        $shift->closing_cash = 0.00;
        $shift->opening_cash_minibar = 1200.00;
        $shift->closing_cash_minibar = 0.00;
        $shift->notes = 'Test shift with high volume transaction data for print pagination testing.';
        $shift->save();

        // Target clean up of previous test data only
        $testBookingIds = Booking::where('booking_ref', 'like', 'BKG-TST-%')->pluck('id');
        $testTxnIds = Transaction::whereIn('booking_id', $testBookingIds)
            ->orWhere('description', 'like', 'Walk-in Minibar POS Sale %')
            ->pluck('id');
            
        InventoryUsage::whereIn('booking_id', $testBookingIds)
            ->orWhereIn('transaction_id', $testTxnIds)
            ->delete();
            
        Transaction::whereIn('id', $testTxnIds)->delete();
        Booking::whereIn('id', $testBookingIds)->delete();

        $rooms = Room::all();
        $items = InventoryItem::where('category', 'minibar')->get();

        if ($rooms->isEmpty() || $items->isEmpty()) {
            throw new \Exception('Please seed Rooms and InventoryItems first.');
        }

        $paymentMethods = ['cash', 'gcash'];
        $bookingTypes = ['short_time', 'overnight'];

        // Seed 30 Stay Bookings
        for ($i = 1; $i <= 30; $i++) {
            $room = $rooms->random();
            $bookingType = $bookingTypes[array_rand($bookingTypes)];
            $payMethod = $paymentMethods[array_rand($paymentMethods)];
            
            // Check in between 0 and 120 minutes after shift start
            $checkIn = $shift->started_at->copy()->addMinutes(rand(1, 120));
            // Check out between 30 and 100 minutes after check in (well within the 4 hour shift)
            $checkOut = $checkIn->copy()->addMinutes(rand(30, 100));
            $expectedOut = $checkOut;
            
            $baseAmount = ($bookingType === 'overnight') ? 1200.00 : 450.00;
            $totalAmount = $baseAmount;
            
            $booking = Booking::create([
                'booking_ref' => 'BKG-TST-' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'room_id' => $room->id,
                'guest_name' => 'Test Guest ' . $i,
                'guest_contact' => '0915' . rand(1000000, 9999999),
                'num_guests' => rand(1, 2),
                'booking_type' => $bookingType,
                'short_time_hours' => ($bookingType === 'short_time') ? 3 : null,
                'check_in' => $checkIn,
                'expected_check_out' => $expectedOut,
                'check_out' => $checkOut,
                'status' => 'checked_out',
                'payment_status' => 'paid',
                'base_amount' => $baseAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $totalAmount,
                'payment_method' => $payMethod,
                'cash_amount' => ($payMethod === 'cash') ? $totalAmount : 0.00,
                'gcash_amount' => ($payMethod === 'gcash') ? $totalAmount : 0.00,
                'checked_in_by' => $maria->id,
                'checked_out_by' => $maria->id,
                'created_at' => $checkIn,
            ]);

            // Create Check-in/Check-out Transaction
            $txn = Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'check_out',
                'description' => 'Payment for stay ' . $booking->booking_ref,
                'amount' => $totalAmount,
                'payment_method' => $payMethod,
                'cash_amount' => ($payMethod === 'cash') ? $totalAmount : 0.00,
                'gcash_amount' => ($payMethod === 'gcash') ? $totalAmount : 0.00,
                'processed_by' => $maria->id,
                'created_at' => $checkOut,
            ]);
            
            // Add some minibar charge for checkout rooms (35% chance)
            if (rand(1, 100) <= 35) {
                $item = $items->random();
                $qty = rand(1, 3);
                $totalPrice = $qty * $item->selling_price;
                
                InventoryUsage::create([
                    'booking_id' => $booking->id,
                    'transaction_id' => $txn->id,
                    'item_id' => $item->id,
                    'quantity' => $qty,
                    'unit_price' => $item->selling_price,
                    'total_price' => $totalPrice,
                    'recorded_by' => $maria->id,
                    'shift_id' => $shift->id,
                    'created_at' => $checkOut,
                ]);
            }
        }

        // Seed 60 Minibar & Walk-in POS transactions
        for ($j = 1; $j <= 60; $j++) {
            $payMethod = $paymentMethods[array_rand($paymentMethods)];
            // Create POS sales between 10 and 230 minutes of shift start
            $createdAt = $shift->started_at->copy()->addMinutes(rand(10, 230));
            
            // Create transaction first
            $transaction = Transaction::create([
                'transaction_type' => 'pos_sale',
                'description' => 'Walk-in Minibar POS Sale #' . $j,
                'amount' => 0.00, // will calculate later
                'payment_method' => $payMethod,
                'cash_amount' => 0.00,
                'gcash_amount' => 0.00,
                'processed_by' => $maria->id,
                'created_at' => $createdAt,
            ]);

            // Add 1-3 items sold
            $numItems = rand(1, 3);
            $totalSales = 0;
            for ($k = 0; $k < $numItems; $k++) {
                $item = $items->random();
                $qty = rand(1, 4);
                $totalPrice = $qty * $item->selling_price;
                $totalSales += $totalPrice;

                InventoryUsage::create([
                    'transaction_id' => $transaction->id,
                    'item_id' => $item->id,
                    'quantity' => $qty,
                    'unit_price' => $item->selling_price,
                    'total_price' => $totalPrice,
                    'recorded_by' => $maria->id,
                    'shift_id' => $shift->id,
                    'created_at' => $createdAt,
                ]);
            }

            // Update transaction with actual totals
            $transaction->amount = $totalSales;
            if ($payMethod === 'cash') {
                $transaction->cash_amount = $totalSales;
            } else {
                $transaction->gcash_amount = $totalSales;
            }
            $transaction->save();
        }
    }
}
