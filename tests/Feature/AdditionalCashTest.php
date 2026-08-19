<?php

namespace Tests\Feature;

use App\Models\AdditionalCash;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_additional_cash()
    {
        $response = $this->get('/additional-cash');
        $response->assertRedirect('/login');
    }

    public function test_authorized_users_can_view_additional_cash_list()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/additional-cash');
        $response->assertStatus(200);
    }

    public function test_authorized_users_can_store_additional_cash()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ShiftSession::create([
            'user_id' => $admin->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 0,
        ]);

        $response = $this->actingAs($admin)->post('/additional-cash', [
            'income_date' => '2026-06-29',
            'amount' => 5000.00,
            'cash_drawer' => 'room',
            'notes' => 'Top up drawer cash float',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('additional_cash', [
            'amount' => 5000.00,
            'cash_drawer' => 'room',
            'notes' => 'Top up drawer cash float',
        ]);
    }

    public function test_validation_prevents_empty_fields_or_negative_amounts()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/additional-cash', [
            'income_date' => '',
            'amount' => -100,
            'cash_drawer' => 'invalid_drawer',
            'notes' => '',
        ]);

        $response->assertSessionHasErrors(['income_date', 'amount', 'cash_drawer']);
    }

    public function test_posted_additional_cash_cannot_be_updated_or_deleted()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ShiftSession::create([
            'user_id' => $admin->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 0,
        ]);
        $this->actingAs($admin)->post('/additional-cash', [
            'income_date' => '2026-06-29',
            'amount' => 1200.00,
            'cash_drawer' => 'room',
            'notes' => 'Initial Cash Notes',
        ])->assertRedirect();
        $income = AdditionalCash::first();

        $this->actingAs($admin)->post("/additional-cash/{$income->id}", [
            'income_date' => '2026-06-30',
            'amount' => 1500.00,
            'cash_drawer' => 'minibar',
            'notes' => 'Updated Cash Notes',
        ])->assertForbidden();

        $this->actingAs($admin)->delete("/additional-cash/{$income->id}")->assertForbidden();
        $this->assertDatabaseHas('additional_cash', [
            'id' => $income->id,
            'amount' => 1200.00,
            'status' => AdditionalCash::STATUS_POSTED,
        ]);
    }

    public function test_cashier_without_active_shift_can_view_additional_cash_in_read_only_mode()
    {
        $cashier = User::factory()->create(['role' => 'front_desk']);

        $response = $this->actingAs($cashier)->get('/additional-cash');
        $response->assertStatus(200);
    }

    public function test_cashier_with_active_shift_can_view_additional_cash()
    {
        $cashier = User::factory()->create(['role' => 'front_desk']);

        ShiftSession::create([
            'user_id' => $cashier->id,
            'shift_code' => 'morning',
            'started_at' => now(),
            'opening_cash' => 1000.00,
            'opening_cash_minibar' => 500.00,
        ]);

        $response = $this->actingAs($cashier)->get('/additional-cash');
        $response->assertStatus(200);
    }
}
