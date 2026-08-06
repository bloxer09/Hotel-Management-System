<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_housekeeping_cannot_access_reservations(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);

        $response = $this->actingAs($housekeeper)->get(route('reservations.index'));
        $response->assertStatus(403);
    }

    public function test_admin_can_access_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('settings.audit.index'));
        $response->assertStatus(200);
    }

    public function test_health_check_endpoint_is_publicly_accessible(): void
    {
        $response = $this->get(route('health'));
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'db' => 'ok',
                'cache' => 'ok',
            ]);
    }
}
