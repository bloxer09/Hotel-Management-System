<?php

namespace Tests\Feature;

use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftDenominationCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_start_uses_one_twenty_peso_denomination_and_recalculates_totals(): void
    {
        $user = $this->createCashier();

        $response = $this->actingAs($user)->post(route('shifts.start'), [
            'shift_code' => 'morning',
            'opening_cash' => 9999,
            'opening_denominations' => [
                '0.01' => 0,
                '0.05' => 0,
                '0.25' => 0,
                '1' => 32,
                '5' => 1,
                '10' => 4,
                '20' => 4,
                '50' => 3,
                '100' => 0,
                '200' => 0,
                '500' => 0,
                '1000' => 0,
            ],
            'opening_cash_minibar' => 9999,
            'opening_denominations_minibar' => [
                '20' => 2,
                '100' => 1,
            ],
        ]);

        $response->assertRedirect(route('shifts.index'));

        $shift = ShiftSession::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(307.0, $shift->opening_cash);
        $this->assertSame(140.0, $shift->opening_cash_minibar);
        $this->assertSame(4, $shift->opening_denominations['20']);
        $this->assertArrayNotHasKey('c_20', $shift->opening_denominations);
        $this->assertArrayNotHasKey('b_20', $shift->opening_denominations);
    }

    public function test_shift_end_recalculates_each_drawer_independently(): void
    {
        $user = $this->createCashier();
        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);

        $response = $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 1,
            'closing_denominations' => [
                '1' => 7,
                '20' => 4,
                '50' => 3,
            ],
            'closing_cash_minibar' => 1,
            'closing_denominations_minibar' => [
                '5' => 2,
                '20' => 1,
                '100' => 2,
            ],
            'notes' => 'Test count differs from expected drawer cash.',
        ]);

        $response->assertRedirect(route('shifts.report', $shift->id));

        $shift->refresh();
        $this->assertSame(237.0, $shift->closing_cash);
        $this->assertSame(230.0, $shift->closing_cash_minibar);
        $this->assertNotNull($shift->ended_at);
    }

    public function test_negative_denomination_quantity_is_rejected(): void
    {
        $user = $this->createCashier();

        $response = $this->actingAs($user)
            ->from(route('shifts.index'))
            ->post(route('shifts.start'), [
                'shift_code' => 'morning',
                'opening_cash' => 0,
                'opening_denominations' => ['20' => -1],
                'opening_cash_minibar' => 0,
                'opening_denominations_minibar' => [],
            ]);

        $response->assertRedirect(route('shifts.index'));
        $response->assertSessionHasErrors('opening_denominations.20');
        $this->assertDatabaseCount('shift_sessions', 0);
    }

    private function createCashier(): User
    {
        return User::create([
            'username' => 'shift_counter_' . uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Shift Counter Test',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
    }
}
