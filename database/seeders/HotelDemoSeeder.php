<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\GuestProfile;
use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StayAmenityPolicy;
use App\Models\User;
use App\Support\HotelDateTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development scenarios for Phase 0–4 manual testing.
 * Not for production. Does not invent cash variance, inventory shortage, or disputes.
 */
class HotelDemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'HotelDemo!26';

    public function run(): void
    {
        $deskA = $this->seedUsers();
        $rooms = $this->seedRooms();
        $items = $this->seedInventory();
        $this->seedAmenityPolicies($items);
        $this->seedBookings($deskA, $rooms);

        $this->command?->info('HotelDemoSeeder complete. Demo password: '.self::DEMO_PASSWORD);
    }

    private function seedUsers(): User
    {
        $password = Hash::make(self::DEMO_PASSWORD);

        $users = [
            ['username' => 'admin', 'full_name' => 'System Administrator', 'role' => 'admin', 'email' => 'admin@hotel.com'],
            ['username' => 'frontdesk_a', 'full_name' => 'Front Desk A', 'role' => 'front_desk', 'email' => 'frontdesk.a@hotel.com'],
            ['username' => 'frontdesk_b', 'full_name' => 'Front Desk B', 'role' => 'front_desk', 'email' => 'frontdesk.b@hotel.com'],
            ['username' => 'housekeeping1', 'full_name' => 'Ana Housekeeping', 'role' => 'housekeeping', 'email' => 'housekeeping@hotel.com'],
        ];

        $deskA = null;
        foreach ($users as $row) {
            $user = User::updateOrCreate(
                ['username' => $row['username']],
                [
                    'password' => $password,
                    'full_name' => $row['full_name'],
                    'role' => $row['role'],
                    'email' => $row['email'],
                    'is_active' => true,
                ]
            );
            if ($row['username'] === 'frontdesk_a') {
                $deskA = $user;
            }
        }

        return $deskA;
    }

    /**
     * @return array<string, Room>
     */
    private function seedRooms(): array
    {
        $types = [
            'Budget Room' => ['base_rate' => 500, 'hourly_rate' => 100, 'short_time_3h_rate' => 300, 'short_time_6h_rate' => 600, 'short_time_12h_rate' => 1200, 'short_time_24h_rate' => 2400, 'max_occupancy' => 1],
            'Standard Single' => ['base_rate' => 800, 'hourly_rate' => 150, 'short_time_3h_rate' => 450, 'short_time_6h_rate' => 900, 'short_time_12h_rate' => 1800, 'short_time_24h_rate' => 3600, 'max_occupancy' => 1],
            'Standard Double' => ['base_rate' => 1200, 'hourly_rate' => 200, 'short_time_3h_rate' => 600, 'short_time_6h_rate' => 1200, 'short_time_12h_rate' => 2400, 'short_time_24h_rate' => 4800, 'max_occupancy' => 2],
            'Deluxe Double' => ['base_rate' => 1800, 'hourly_rate' => 300, 'short_time_3h_rate' => 900, 'short_time_6h_rate' => 1800, 'short_time_12h_rate' => 3600, 'short_time_24h_rate' => 7200, 'max_occupancy' => 2],
            'Family Room' => ['base_rate' => 2500, 'hourly_rate' => 400, 'short_time_3h_rate' => 1200, 'short_time_6h_rate' => 2400, 'short_time_12h_rate' => 4800, 'short_time_24h_rate' => 9600, 'max_occupancy' => 4],
            'Suite' => ['base_rate' => 4500, 'hourly_rate' => 700, 'short_time_3h_rate' => 2100, 'short_time_6h_rate' => 4200, 'short_time_12h_rate' => 8400, 'short_time_24h_rate' => 16800, 'max_occupancy' => 2],
        ];

        $typeModels = [];
        foreach ($types as $name => $attrs) {
            $typeModels[$name] = RoomType::firstOrCreate(
                ['type_name' => $name],
                array_merge($attrs, ['description' => $name, 'amenities' => 'WiFi'])
            );
        }

        $definitions = [
            '101' => ['type' => 'Budget Room', 'floor' => 1, 'status' => 'vacant'],
            '102' => ['type' => 'Budget Room', 'floor' => 1, 'status' => 'vacant'],
            '103' => ['type' => 'Standard Single', 'floor' => 1, 'status' => 'vacant'],
            '104' => ['type' => 'Standard Single', 'floor' => 1, 'status' => 'vacant'],
            '202' => ['type' => 'Standard Double', 'floor' => 2, 'status' => 'occupied'],
            '203' => ['type' => 'Deluxe Double', 'floor' => 2, 'status' => 'occupied'],
            '204' => ['type' => 'Deluxe Double', 'floor' => 2, 'status' => 'occupied'],
            '301' => ['type' => 'Family Room', 'floor' => 3, 'status' => 'cleaning'],
            '401' => ['type' => 'Suite', 'floor' => 4, 'status' => 'vacant'],
        ];

        $rooms = [];
        foreach ($definitions as $number => $row) {
            $room = Room::firstOrCreate(
                ['room_number' => $number],
                [
                    'room_type_id' => $typeModels[$row['type']]->id,
                    'floor' => $row['floor'],
                    'status' => $row['status'],
                ]
            );
            $room->status = $row['status'];
            $room->cleaning_started_at = $row['status'] === 'cleaning' ? now() : null;
            $room->save();
            $rooms[$number] = $room;
        }

        return $rooms;
    }

    /**
     * @return array<string, InventoryItem>
     */
    private function seedInventory(): array
    {
        $catalog = [
            'Coke' => [
                'category' => 'minibar',
                'unit' => 'can',
                'current_stock' => 35,
                'minimum_stock' => 8,
                'unit_cost' => 12.00,
                'selling_price' => 25.00,
                'is_turnover_tracked' => true,
            ],
            'Mineral Water' => [
                'category' => 'minibar',
                'unit' => 'bottle',
                'current_stock' => 40,
                'minimum_stock' => 10,
                'unit_cost' => 10.00,
                'selling_price' => 35.00,
                'is_turnover_tracked' => true,
            ],
            'Safeguard' => [
                'category' => 'toiletries',
                'unit' => 'pc',
                'current_stock' => 20,
                'minimum_stock' => 5,
                'unit_cost' => 8.00,
                'selling_price' => 0.00,
                'is_turnover_tracked' => true,
            ],
            'Shampoo' => [
                'category' => 'toiletries',
                'unit' => 'pc',
                'current_stock' => 20,
                'minimum_stock' => 5,
                'unit_cost' => 5.00,
                'selling_price' => 0.00,
                'is_turnover_tracked' => true,
            ],
        ];

        $items = [];
        foreach ($catalog as $name => $attrs) {
            $item = InventoryItem::query()
                ->where('category', $attrs['category'])
                ->where('normalized_name', InventoryItem::normalizeName($name))
                ->first();

            if ($item) {
                $item->fill(array_merge($attrs, ['item_name' => $name, 'is_active' => true]));
                $item->save();
            } else {
                $item = InventoryItem::create(array_merge($attrs, [
                    'item_name' => $name,
                    'is_active' => true,
                ]));
            }

            $items[$name] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, InventoryItem>  $items
     */
    private function seedAmenityPolicies(array $items): void
    {
        foreach ([StayAmenityPolicy::STAY_OVERNIGHT, StayAmenityPolicy::STAY_SHORT_TIME_24] as $stayKey) {
            foreach (['Safeguard', 'Shampoo'] as $name) {
                StayAmenityPolicy::updateOrCreate(
                    [
                        'stay_key' => $stayKey,
                        'inventory_item_id' => $items[$name]->id,
                    ],
                    [
                        'default_quantity' => 1,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<string, Room>  $rooms
     */
    private function seedBookings(User $deskA, array $rooms): void
    {
        $now = HotelDateTime::now();

        $this->upsertBooking('DEMO-202', [
            'room' => $rooms['202'],
            'guest_name' => 'Juan Dela Cruz',
            'guest_contact' => '09171112222',
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => $now->copy()->startOfDay()->addHours(14),
            'expected_check_out' => $now->copy()->addDay()->startOfDay()->addHours(12),
            'status' => 'active',
            'base_amount' => 1200,
            'notes' => 'Demo walk-in occupied stay. Ready for POS / add-item through the app.',
        ], $deskA);

        $this->upsertBooking('DEMO-203', [
            'room' => $rooms['203'],
            'guest_name' => 'Maria Reyes',
            'guest_contact' => '09173334444',
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 2,
            'check_in' => $now->copy()->subDay()->startOfDay()->addHours(14),
            'expected_check_out' => $now->copy()->addDay()->startOfDay()->addHours(12),
            'status' => 'active',
            'base_amount' => 3600,
            'notes' => 'Demo multi-night stay. Eligible for overnight complimentary Safeguard + Shampoo. Do not auto-issue in seeder.',
        ], $deskA);

        $this->upsertBooking('DEMO-204', [
            'room' => $rooms['204'],
            'guest_name' => 'Pedro Santos',
            'guest_contact' => '09175556666',
            'booking_type' => 'overnight',
            'booking_source' => 'walk_in',
            'num_nights' => 1,
            'check_in' => $now->copy()->subDay()->startOfDay()->addHours(14),
            'expected_check_out' => $now->copy()->subMinutes(30),
            'status' => 'active',
            'base_amount' => 1800,
            'notes' => 'Demo checkout-due stay. Add minibar items here, then check out to test Phase 0 settlement. No seeded usages.',
        ], $deskA);

        $this->upsertBooking('DEMO-401', [
            'room' => $rooms['401'],
            'guest_name' => 'Ana Future',
            'guest_contact' => '09177778888',
            'booking_type' => 'overnight',
            'booking_source' => 'reservation',
            'num_nights' => 1,
            'check_in' => $now->copy()->addDay()->startOfDay()->addHours(14),
            'expected_check_out' => $now->copy()->addDays(2)->startOfDay()->addHours(12),
            'status' => 'reserved',
            'base_amount' => 4500,
            'notes' => 'Demo future suite reservation. Room stays vacant until check-in.',
        ], $deskA);

        $this->upsertBooking('DEMO-CXL', [
            'room' => $rooms['104'],
            'guest_name' => 'Cancelled Guest',
            'guest_contact' => '09179990000',
            'booking_type' => 'overnight',
            'booking_source' => 'reservation',
            'num_nights' => 1,
            'check_in' => $now->copy()->addDays(3)->startOfDay()->addHours(14),
            'expected_check_out' => $now->copy()->addDays(4)->startOfDay()->addHours(12),
            'status' => 'cancelled',
            'base_amount' => 800,
            'notes' => 'Demo cancelled reservation. Stock and room 104 remain unused.',
        ], $deskA);
    }

    private function upsertBooking(string $ref, array $data, User $desk): Booking
    {
        $guest = GuestProfile::firstOrCreate(
            ['contact_number' => $data['guest_contact']],
            [
                'full_name' => $data['guest_name'],
                'id_type' => 'National ID',
                'id_number' => 'DEMO-'.$ref,
            ]
        );

        $amount = (float) $data['base_amount'];
        $room = $data['room'];

        return Booking::updateOrCreate(
            ['booking_ref' => $ref],
            [
                'room_id' => $room->id,
                'guest_profile_id' => $guest->id,
                'booked_by_user_id' => $desk->id,
                'guest_name' => $data['guest_name'],
                'booker_name' => $data['guest_name'],
                'guest_contact' => $data['guest_contact'],
                'booker_contact' => $data['guest_contact'],
                'num_guests' => 1,
                'booking_type' => $data['booking_type'],
                'booking_source' => $data['booking_source'],
                'num_nights' => $data['num_nights'],
                'check_in' => $data['check_in']->format('Y-m-d H:i:s'),
                'expected_check_out' => $data['expected_check_out']->format('Y-m-d H:i:s'),
                'check_out' => null,
                'status' => $data['status'],
                'payment_status' => 'unpaid',
                'base_amount' => $amount,
                'total_amount' => $amount,
                'amount_paid' => 0,
                'payment_method' => 'cash',
                'checked_in_by' => $data['status'] === 'active' ? $desk->id : null,
                'notes' => $data['notes'],
            ]
        );
    }
}
