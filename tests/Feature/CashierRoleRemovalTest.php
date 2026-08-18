<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Support\HotelDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierRoleRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_cashier_user_is_safely_migrated_to_front_desk(): void
    {
        $this->expandRoleEnumToIncludeCashier();

        $userId = DB::table('users')->insertGetId([
            'username' => 'legacy_cashier_'.substr(uniqid(), -8),
            'password' => bcrypt('password'),
            'full_name' => 'Legacy Cashier',
            'role' => 'cashier',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = ShiftSession::create([
            'user_id' => $userId,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 0,
        ]);

        [$booking] = $this->makeStay($userId, [
            'status' => 'checked_out',
            'expected_check_out' => HotelDateTime::now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $transaction = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Stay payment',
            'amount' => 750,
            'payment_method' => 'cash',
            'cash_amount' => 750,
            'processed_by' => $userId,
        ]);

        $payment = Payment::create([
            'receipt_number' => 'RCP-'.strtoupper(substr(uniqid(), -8)),
            'received_at' => now(),
            'payer_name' => 'Legacy Guest',
            'payment_method_code' => 'cash',
            'amount' => 750,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $userId,
            'shift_id' => $shift->id,
        ]);

        DB::table('users')->where('role', 'cashier')->update(['role' => 'front_desk']);
        $this->tightenRoleEnum();

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'username' => DB::table('users')->where('id', $userId)->value('username'),
            'role' => 'front_desk',
        ]);
        $this->assertSame(1, User::where('id', $userId)->count());
        $this->assertDatabaseHas('shift_sessions', ['id' => $shift->id, 'user_id' => $userId]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'processed_by' => $userId]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'recorded_by' => $userId]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booked_by_user_id' => $userId]);
    }

    public function test_new_user_cannot_be_created_with_role_cashier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('settings.users.store'), [
            'name' => 'Should Fail',
            'username' => 'try_cashier_'.substr(uniqid(), -6),
            'password' => 'password',
            'role' => 'cashier',
            'is_active' => true,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['role' => 'cashier']);
    }

    public function test_final_allowed_roles_are_admin_front_desk_and_housekeeping(): void
    {
        $this->assertSame(['admin', 'front_desk', 'housekeeping'], UserRole::values());

        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $this->actingAs($admin)->post(route('settings.users.store'), [
                'name' => ucfirst($role).' Staff',
                'username' => $role.'_ok_'.substr(uniqid(), -6),
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
            ])->assertSessionDoesntHaveErrors('role');
        }
    }

    public function test_existing_user_shift_and_payment_history_remain_linked_after_role_change(): void
    {
        $user = User::factory()->create(['role' => 'front_desk']);
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 500,
            'opening_cash_minibar' => 0,
        ]);
        [$booking] = $this->makeStay($user->id);
        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Linked history',
            'amount' => 400,
            'payment_method' => 'cash',
            'processed_by' => $user->id,
        ]);

        $user->update(['role' => 'front_desk']);

        $this->assertTrue($user->shiftSessions()->where('id', $shift->id)->exists());
        $this->assertTrue($user->transactions()->exists());
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booked_by_user_id' => $user->id]);
    }

    private function expandRoleEnumToIncludeCashier(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'front_desk', 'cashier', 'housekeeping') NOT NULL DEFAULT 'front_desk'");
        }
    }

    private function tightenRoleEnum(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'front_desk', 'housekeeping') NOT NULL DEFAULT 'front_desk'");
        }
    }

    /**
     * @return array{0: Booking, 1: Room}
     */
    private function makeStay(int $userId, array $overrides = []): array
    {
        $type = RoomType::create([
            'type_name' => 'Role History Room '.uniqid(),
            'base_rate' => 800,
            'hourly_rate' => 150,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'RH'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => 'occupied',
        ]);
        $now = HotelDateTime::now();
        $booking = Booking::create(array_merge([
            'booking_ref' => 'RH'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'booked_by_user_id' => $userId,
            'guest_name' => 'History Guest',
            'num_guests' => 1,
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'check_in' => $now->copy()->subHours(2)->format('Y-m-d H:i:s'),
            'check_out' => $now->copy()->addHour()->format('Y-m-d H:i:s'),
            'expected_check_out' => $now->copy()->addHour()->format('Y-m-d H:i:s'),
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 750,
            'total_amount' => 750,
            'amount_paid' => 750,
            'payment_method' => 'cash',
            'checked_in_by' => $userId,
        ], $overrides));

        return [$booking, $room];
    }
}
