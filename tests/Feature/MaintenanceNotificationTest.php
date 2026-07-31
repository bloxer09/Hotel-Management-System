<?php

namespace Tests\Feature;

use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairing_and_for_verification_tickets_alert_operational_roles(): void
    {
        $reporter = $this->makeUser('admin');
        $room = $this->makeRoom();

        MaintenanceTicket::create([
            'room_id' => $room->id,
            'reported_by' => $reporter->id,
            'title' => 'Aircon cleaning',
            'priority' => 'low',
            'status' => 'in_progress',
        ]);
        MaintenanceTicket::create([
            'room_id' => $room->id,
            'reported_by' => $reporter->id,
            'title' => 'Door lock replacement',
            'priority' => 'medium',
            'status' => 'for_verification',
        ]);
        MaintenanceTicket::create([
            'room_id' => $room->id,
            'reported_by' => $reporter->id,
            'title' => 'Closed issue',
            'priority' => 'critical',
            'status' => 'closed',
        ]);

        foreach (['admin', 'front_desk', 'housekeeping'] as $role) {
            $response = $this->actingAs($this->makeUser($role))
                ->getJson(route('api.notifications'));

            $response
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('counts.maintenance', 2)
                ->assertJsonFragment(['status' => 'in_progress'])
                ->assertJsonFragment(['status' => 'for_verification']);
        }
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'username' => $role.'_maintenance_'.uniqid(),
            'full_name' => ucfirst(str_replace('_', ' ', $role)).' User',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeRoom(): Room
    {
        $type = RoomType::create([
            'type_name' => 'Maintenance Test Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);

        return Room::create([
            'room_number' => 'MT-'.substr(uniqid(), -6),
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }
}
