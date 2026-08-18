<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SingleActiveRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_staff_member_can_open_the_front_desk_register(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk A']);
        $viewer = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk B']);

        $this->actingAs($operator)
            ->post(route('shifts.start'), $this->shiftPayload())
            ->assertRedirect(route('shifts.index'));

        $response = $this->actingAs($viewer)
            ->from(route('shifts.index'))
            ->post(route('shifts.start'), $this->shiftPayload());

        $response->assertRedirect(route('shifts.index'));
        $response->assertSessionHas('warning');
        $this->assertDatabaseCount('shift_sessions', 1);
        $this->assertSame($operator->id, ShiftSession::active()->firstOrFail()->user_id);
    }

    public function test_other_front_desk_staff_can_view_pages_and_receive_viewer_mode_props(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk A']);
        $viewer = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk B']);
        $shift = $this->activeShiftFor($operator);

        $this->actingAs($viewer)
            ->get(route('additional-cash.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.viewer_mode', true)
                ->where('auth.can_operate_register', false)
                ->where('auth.active_shift', null)
                ->where('auth.register_shift.id', $shift->id)
                ->where('auth.register_shift.user.id', $operator->id)
            );
    }

    public function test_viewer_cannot_use_a_write_route_that_previously_bypassed_active_shift(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk']);
        $viewer = User::factory()->create(['role' => 'front_desk']);
        $this->activeShiftFor($operator);
        $room = $this->room();

        $response = $this->actingAs($viewer)
            ->from(route('rooms.index'))
            ->post(route('rooms.status', $room), [
                'status' => 'cleaning',
                'notes' => 'Viewer should not change this.',
            ]);

        $response->assertRedirect(route('rooms.index'));
        $response->assertSessionHas('warning');
        $this->assertSame('vacant', $room->fresh()->status);
    }

    public function test_viewer_cannot_create_financial_records(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk']);
        $viewer = User::factory()->create(['role' => 'front_desk']);
        $this->activeShiftFor($operator);

        $response = $this->actingAs($viewer)
            ->from(route('additional-cash.index'))
            ->post(route('additional-cash.store'), [
                'income_date' => now()->toDateString(),
                'amount' => 500,
                'cash_drawer' => 'room',
                'notes' => 'Blocked viewer entry',
            ]);

        $response->assertRedirect(route('additional-cash.index'));
        $response->assertSessionHas('warning');
        $this->assertDatabaseCount('additional_cash', 0);
    }

    public function test_assigned_operator_can_change_operational_data(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk']);
        $this->activeShiftFor($operator);
        $room = $this->room();

        $this->actingAs($operator)
            ->from(route('rooms.index'))
            ->post(route('rooms.status', $room), ['status' => 'cleaning'])
            ->assertRedirect(route('rooms.index'));

        $this->assertSame('cleaning', $room->fresh()->status);
    }

    public function test_admin_keeps_write_access_and_register_override_is_audited(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk A']);
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Duty Manager']);
        $shift = $this->activeShiftFor($operator);
        $room = $this->room();

        $this->actingAs($admin)
            ->from(route('rooms.index'))
            ->post(route('rooms.status', $room), ['status' => 'cleaning'])
            ->assertRedirect(route('rooms.index'));

        $this->assertSame('cleaning', $room->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'ADMIN_REGISTER_OVERRIDE',
            'module' => 'shift_sessions',
            'record_id' => $shift->id,
        ]);
    }

    public function test_new_operator_start_is_audited_as_a_handover(): void
    {
        $previousOperator = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk A']);
        $newOperator = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Front Desk B']);
        $previousShift = $this->activeShiftFor($previousOperator);
        $previousShift->update([
            'ended_at' => now(),
            'active_register_key' => null,
            'closing_cash' => 1000,
            'closing_cash_minibar' => 500,
        ]);

        $this->actingAs($newOperator)
            ->post(route('shifts.start'), $this->shiftPayload())
            ->assertRedirect(route('shifts.index'));

        $newShift = ShiftSession::active()->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $newOperator->id,
            'action' => 'SHIFT_HANDOVER',
            'module' => 'shift_sessions',
            'record_id' => $newShift->id,
        ]);
        $this->assertStringContainsString(
            'Front Desk A',
            AuditLog::where('action', 'SHIFT_HANDOVER')->firstOrFail()->reason
        );
    }

    public function test_shift_owner_can_download_an_editable_excel_working_copy(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk']);
        $shift = $this->activeShiftFor($operator);

        $response = $this->actingAs($operator)
            ->get(route('shifts.working-copy', $shift));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $response->assertHeader('content-disposition');
        $this->assertStringStartsWith('PK', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $operator->id,
            'action' => 'SHIFT_WORKING_COPY_EXPORTED',
            'module' => 'shift_sessions',
            'record_id' => $shift->id,
        ]);
    }

    public function test_other_staff_cannot_download_another_operators_working_copy(): void
    {
        $operator = User::factory()->create(['role' => 'front_desk']);
        $viewer = User::factory()->create(['role' => 'front_desk']);
        $shift = $this->activeShiftFor($operator);

        $this->actingAs($viewer)
            ->get(route('shifts.working-copy', $shift))
            ->assertForbidden();
    }

    private function activeShiftFor(User $user): ShiftSession
    {
        return ShiftSession::create([
            'user_id' => $user->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
    }

    private function shiftPayload(): array
    {
        return [
            'shift_code' => 'morning',
            'opening_cash' => 1000,
            'opening_denominations' => [],
            'opening_cash_minibar' => 500,
            'opening_denominations_minibar' => [],
            'notes' => 'Shift test',
        ];
    }

    private function room(): Room
    {
        $type = RoomType::create([
            'type_name' => 'Viewer Test Room',
            'base_rate' => 1000,
            'hourly_rate' => 100,
            'max_occupancy' => 2,
        ]);

        return Room::create([
            'room_number' => 'VIEW-1',
            'room_type_id' => $type->id,
            'status' => 'vacant',
            'floor' => 1,
        ]);
    }
}
