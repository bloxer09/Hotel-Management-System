<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_notifications_is_redirected(): void
    {
        $response = $this->get(route('api.notifications'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_operational_user_can_fetch_notifications(): void
    {
        $user = User::factory()->create(['role' => 'front_desk']);

        $response = $this->actingAs($user)->getJson(route('api.notifications'));
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'generated_at',
                'minutes_ahead',
                'counts',
                'items',
            ]);
    }

    public function test_housekeeping_can_fetch_notifications(): void
    {
        $user = User::factory()->create(['role' => 'housekeeping']);

        $this->actingAs($user)->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('role', 'housekeeping');
    }
}
