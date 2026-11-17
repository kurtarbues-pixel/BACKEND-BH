<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleBasedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_room_application_notifies_only_its_responsible_landlord(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $owner->id, 'status' => 'available']);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/rooms/{$room->id}/inquiries", [
            'message' => 'I am interested in renting this room.',
        ])->assertCreated();

        $this->assertSame(1, Notification::where('user_id', $owner->id)->where('type', 'inquiry.new')->count());
        $this->assertSame(0, Notification::where('user_id', $otherLandlord->id)->count());
        $this->assertSame(0, Notification::where('user_id', $tenant->id)->count());
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
    }

    public function test_administrator_alert_helper_remains_available_for_platform_level_events(): void
    {
        $admin = User::factory()->admin()->create();
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        Notification::notifyAdministrators('system.security', 'Security alert', 'An important platform security event was detected.');

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'system.security')->count());
        $this->assertSame(0, Notification::where('type', 'system.security')->whereIn('user_id', [$landlord->id, $tenant->id])->count());
    }

    public function test_administrator_notification_helper_rejects_routine_rental_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Notification::notifyAdministrators('system.payment', 'Payment recorded', 'Routine payment activity.');
    }
}
