<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Income;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_incomes()
    {
        $response = $this->get('/incomes');
        $response->assertRedirect('/login');
    }

    public function test_authorized_users_can_view_incomes_list()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/incomes');
        $response->assertStatus(200);
    }

    public function test_authorized_users_can_store_income()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/incomes', [
            'income_date' => '2026-06-29',
            'amount' => 5000.00,
            'cash_drawer' => 'room',
            'notes' => 'Top up drawer cash float',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('incomes', [
            'amount' => 5000.00,
            'cash_drawer' => 'room',
            'notes' => 'Top up drawer cash float',
        ]);
    }

    public function test_validation_prevents_empty_fields_or_negative_amounts()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/incomes', [
            'income_date' => '',
            'amount' => -100,
            'cash_drawer' => 'invalid_drawer',
            'notes' => '',
        ]);

        $response->assertSessionHasErrors(['income_date', 'amount', 'cash_drawer']);
    }

    public function test_authorized_users_can_update_income()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $income = Income::create([
            'income_date' => '2026-06-29',
            'amount' => 1200.00,
            'cash_drawer' => 'room',
            'notes' => 'Initial Income Notes',
            'recorded_by' => $admin->id
        ]);

        $response = $this->actingAs($admin)->post("/incomes/{$income->id}", [
            'income_date' => '2026-06-30',
            'amount' => 1500.00,
            'cash_drawer' => 'minibar',
            'notes' => 'Updated Income Notes',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('incomes', [
            'id' => $income->id,
            'amount' => 1500.00,
            'cash_drawer' => 'minibar',
            'notes' => 'Updated Income Notes',
        ]);
    }

    public function test_authorized_users_can_delete_income()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $income = Income::create([
            'income_date' => '2026-06-29',
            'amount' => 1200.00,
            'notes' => 'To be deleted',
            'recorded_by' => $admin->id
        ]);

        $response = $this->actingAs($admin)->delete("/incomes/{$income->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('incomes', [
            'id' => $income->id,
        ]);
    }

    public function test_cashier_without_active_shift_is_redirected_from_incomes()
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $response = $this->actingAs($cashier)->get('/incomes');
        $response->assertRedirect(route('shifts.index'));
    }

    public function test_cashier_with_active_shift_can_view_incomes()
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        
        \App\Models\ShiftSession::create([
            'user_id' => $cashier->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        $response = $this->actingAs($cashier)->get('/incomes');
        $response->assertStatus(200);
    }
}
