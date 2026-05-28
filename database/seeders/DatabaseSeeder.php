<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\RoomType;
use App\Models\Room;
use App\Models\PeakDate;
use App\Models\InventoryItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Users (Default password: password)
        $hashedPassword = Hash::make('password');

        $admin = User::create([
            'username' => 'admin',
            'password' => $hashedPassword,
            'full_name' => 'System Administrator',
            'role' => 'admin',
            'email' => 'admin@hotel.com',
            'phone' => '09171234567',
            'is_active' => true,
        ]);

        User::create([
            'username' => 'frontdesk1',
            'password' => $hashedPassword,
            'full_name' => 'Maria Santos',
            'role' => 'front_desk',
            'email' => 'maria@hotel.com',
            'phone' => '09172345678',
            'is_active' => true,
        ]);

        User::create([
            'username' => 'cashier1',
            'password' => $hashedPassword,
            'full_name' => 'Juan Dela Cruz',
            'role' => 'cashier',
            'email' => 'juan@hotel.com',
            'phone' => '09173456789',
            'is_active' => true,
        ]);

        User::create([
            'username' => 'housekeeping1',
            'password' => $hashedPassword,
            'full_name' => 'Ana Housekeeping',
            'role' => 'housekeeping',
            'email' => 'housekeeping@hotel.com',
            'phone' => '09174567890',
            'is_active' => true,
        ]);

        // 2. Seed Room Types
        $types = [
            [
                'type_name' => 'Standard Single',
                'description' => 'Comfortable single room with basic amenities',
                'base_rate' => 800.00,
                'hourly_rate' => 150.00,
                'short_time_3h_rate' => 450.00,
                'short_time_6h_rate' => 900.00,
                'short_time_12h_rate' => 1800.00,
                'short_time_24h_rate' => 3600.00,
                'max_occupancy' => 1,
                'amenities' => 'AC, TV, Private Bathroom, WiFi',
            ],
            [
                'type_name' => 'Standard Double',
                'description' => 'Spacious double room for couples',
                'base_rate' => 1200.00,
                'hourly_rate' => 200.00,
                'short_time_3h_rate' => 600.00,
                'short_time_6h_rate' => 1200.00,
                'short_time_12h_rate' => 2400.00,
                'short_time_24h_rate' => 4800.00,
                'max_occupancy' => 2,
                'amenities' => 'AC, TV, Private Bathroom, WiFi, Double Bed',
            ],
            [
                'type_name' => 'Deluxe Double',
                'description' => 'Premium double room with city view',
                'base_rate' => 1800.00,
                'hourly_rate' => 300.00,
                'short_time_3h_rate' => 900.00,
                'short_time_6h_rate' => 1800.00,
                'short_time_12h_rate' => 3600.00,
                'short_time_24h_rate' => 7200.00,
                'max_occupancy' => 2,
                'amenities' => 'AC, Smart TV, Private Bathroom, WiFi, Minibar, City View',
            ],
            [
                'type_name' => 'Family Room',
                'description' => 'Large room for families up to 4 persons',
                'base_rate' => 2500.00,
                'hourly_rate' => 400.00,
                'short_time_3h_rate' => 1200.00,
                'short_time_6h_rate' => 2400.00,
                'short_time_12h_rate' => 4800.00,
                'short_time_24h_rate' => 9600.00,
                'max_occupancy' => 4,
                'amenities' => 'AC, TV, Private Bathroom, WiFi, 2 Double Beds',
            ],
            [
                'type_name' => 'Suite',
                'description' => 'Luxury suite with separate living area',
                'base_rate' => 4500.00,
                'hourly_rate' => 700.00,
                'short_time_3h_rate' => 2100.00,
                'short_time_6h_rate' => 4200.00,
                'short_time_12h_rate' => 8400.00,
                'short_time_24h_rate' => 16800.00,
                'max_occupancy' => 2,
                'amenities' => 'AC, Smart TV, Jacuzzi, Kitchenette, WiFi, Minibar, Balcony',
            ],
            [
                'type_name' => 'Budget Room',
                'description' => 'Affordable room for budget travelers',
                'base_rate' => 500.00,
                'hourly_rate' => 100.00,
                'short_time_3h_rate' => 300.00,
                'short_time_6h_rate' => 600.00,
                'short_time_12h_rate' => 1200.00,
                'short_time_24h_rate' => 2400.00,
                'max_occupancy' => 1,
                'amenities' => 'Fan, TV, Shared Bathroom, WiFi',
            ],
        ];

        $seededTypes = [];
        foreach ($types as $type) {
            $seededTypes[$type['type_name']] = RoomType::create($type);
        }

        // 3. Seed Rooms
        $rooms = [
            ['room_number' => '101', 'room_type' => 'Budget Room', 'floor' => 1],
            ['room_number' => '102', 'room_type' => 'Budget Room', 'floor' => 1],
            ['room_number' => '103', 'room_type' => 'Standard Single', 'floor' => 1],
            ['room_number' => '104', 'room_type' => 'Standard Single', 'floor' => 1],
            ['room_number' => '201', 'room_type' => 'Standard Double', 'floor' => 2],
            ['room_number' => '202', 'room_type' => 'Standard Double', 'floor' => 2],
            ['room_number' => '203', 'room_type' => 'Deluxe Double', 'floor' => 2],
            ['room_number' => '204', 'room_type' => 'Deluxe Double', 'floor' => 2],
            ['room_number' => '301', 'room_type' => 'Family Room', 'floor' => 3],
            ['room_number' => '302', 'room_type' => 'Family Room', 'floor' => 3],
            ['room_number' => '401', 'room_type' => 'Suite', 'floor' => 4],
            ['room_number' => '402', 'room_type' => 'Suite', 'floor' => 4],
        ];

        foreach ($rooms as $room) {
            Room::create([
                'room_number' => $room['room_number'],
                'room_type_id' => $seededTypes[$room['room_type']]->id,
                'floor' => $room['floor'],
                'status' => 'vacant',
            ]);
        }

        // 4. Seed Peak Dates
        $peakDates = [
            ['date_from' => '2025-12-24', 'date_to' => '2025-12-26', 'label' => 'Christmas Eve & Day', 'surcharge_amount' => 500.00, 'surcharge_type' => 'fixed'],
            ['date_from' => '2025-12-31', 'date_to' => '2026-01-01', 'label' => 'New Year', 'surcharge_amount' => 500.00, 'surcharge_type' => 'fixed'],
            ['date_from' => '2026-02-14', 'date_to' => '2026-02-14', 'label' => "Valentine's Day", 'surcharge_amount' => 300.00, 'surcharge_type' => 'fixed'],
            ['date_from' => '2026-04-09', 'date_to' => '2026-04-10', 'label' => 'Holy Week', 'surcharge_amount' => 200.00, 'surcharge_type' => 'fixed'],
            ['date_from' => '2026-06-12', 'date_to' => '2026-06-12', 'label' => 'Independence Day', 'surcharge_amount' => 150.00, 'surcharge_type' => 'fixed'],
        ];

        foreach ($peakDates as $peak) {
            PeakDate::create([
                'date_from' => $peak['date_from'],
                'date_to' => $peak['date_to'],
                'label' => $peak['label'],
                'surcharge_amount' => $peak['surcharge_amount'],
                'surcharge_type' => $peak['surcharge_type'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]);
        }

        // 5. Seed Inventory Items
        $inventory = [
            ['item_name' => 'Mineral Water 500ml', 'category' => 'minibar', 'unit' => 'bottle', 'current_stock' => 50, 'minimum_stock' => 10, 'unit_cost' => 15.00, 'selling_price' => 35.00],
            ['item_name' => 'Soft Drink (Cola)', 'category' => 'minibar', 'unit' => 'can', 'current_stock' => 40, 'minimum_stock' => 10, 'unit_cost' => 25.00, 'selling_price' => 50.00],
            ['item_name' => 'Beer (Local)', 'category' => 'minibar', 'unit' => 'can', 'current_stock' => 30, 'minimum_stock' => 8, 'unit_cost' => 40.00, 'selling_price' => 80.00],
            ['item_name' => 'Peanuts (Small)', 'category' => 'minibar', 'unit' => 'pack', 'current_stock' => 25, 'minimum_stock' => 8, 'unit_cost' => 20.00, 'selling_price' => 45.00],
            ['item_name' => 'Chocolate Bar', 'category' => 'minibar', 'unit' => 'pc', 'current_stock' => 20, 'minimum_stock' => 5, 'unit_cost' => 30.00, 'selling_price' => 60.00],
            ['item_name' => 'Shampoo Sachet', 'category' => 'toiletries', 'unit' => 'pc', 'current_stock' => 100, 'minimum_stock' => 20, 'unit_cost' => 5.00, 'selling_price' => 0.00],
            ['item_name' => 'Conditioner Sachet', 'category' => 'toiletries', 'unit' => 'pc', 'current_stock' => 100, 'minimum_stock' => 20, 'unit_cost' => 5.00, 'selling_price' => 0.00],
            ['item_name' => 'Soap Bar', 'category' => 'toiletries', 'unit' => 'pc', 'current_stock' => 80, 'minimum_stock' => 15, 'unit_cost' => 8.00, 'selling_price' => 0.00],
            ['item_name' => 'Toothbrush & Paste', 'category' => 'toiletries', 'unit' => 'set', 'current_stock' => 50, 'minimum_stock' => 10, 'unit_cost' => 25.00, 'selling_price' => 0.00],
            ['item_name' => 'Shower Cap', 'category' => 'toiletries', 'unit' => 'pc', 'current_stock' => 60, 'minimum_stock' => 10, 'unit_cost' => 5.00, 'selling_price' => 0.00],
            ['item_name' => 'Laundry - Regular', 'category' => 'laundry', 'unit' => 'kg', 'current_stock' => 0, 'minimum_stock' => 0, 'unit_cost' => 0.00, 'selling_price' => 80.00],
            ['item_name' => 'Laundry - Express', 'category' => 'laundry', 'unit' => 'kg', 'current_stock' => 0, 'minimum_stock' => 0, 'unit_cost' => 0.00, 'selling_price' => 150.00],
            ['item_name' => 'Dry Cleaning - Shirt', 'category' => 'laundry', 'unit' => 'pc', 'current_stock' => 0, 'minimum_stock' => 0, 'unit_cost' => 0.00, 'selling_price' => 120.00],
            ['item_name' => 'Extra Towel', 'category' => 'amenities', 'unit' => 'pc', 'current_stock' => 30, 'minimum_stock' => 5, 'unit_cost' => 0.00, 'selling_price' => 50.00],
            ['item_name' => 'Extra Pillow', 'category' => 'amenities', 'unit' => 'pc', 'current_stock' => 20, 'minimum_stock' => 3, 'unit_cost' => 0.00, 'selling_price' => 50.00],
            ['item_name' => 'Extra Blanket', 'category' => 'amenities', 'unit' => 'pc', 'current_stock' => 15, 'minimum_stock' => 3, 'unit_cost' => 0.00, 'selling_price' => 75.00],
            ['item_name' => 'Tissue Box', 'category' => 'supplies', 'unit' => 'box', 'current_stock' => 40, 'minimum_stock' => 10, 'unit_cost' => 20.00, 'selling_price' => 0.00],
            ['item_name' => 'Coffee Sachet', 'category' => 'minibar', 'unit' => 'pack', 'current_stock' => 60, 'minimum_stock' => 15, 'unit_cost' => 10.00, 'selling_price' => 25.00],
        ];

        foreach ($inventory as $item) {
            InventoryItem::create($item);
        }
    }
}
