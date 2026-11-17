<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_load_listing_details_from_the_authorized_room_endpoint(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create([
            'landlord_id' => $landlord->id,
            'title' => 'QA Room Detail',
            'type' => 'Single room',
            'capacity' => 2,
            'description' => 'A bright room near the entrance.',
            'location' => 'East Wing, 2nd floor',
            'price' => '2500.00',
            'amenities' => ['Wi-Fi', 'Aircon'],
            'status' => 'available',
        ]);
        Sanctum::actingAs($tenant);

        $this->getJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $room->id)
            ->assertJsonPath('data.capacity', 2)
            ->assertJsonPath('data.type', 'Single room')
            ->assertJsonPath('data.description', 'A bright room near the entrance.')
            ->assertJsonPath('data.location', 'East Wing, 2nd floor')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.amenities.0', 'Wi-Fi')
            ->assertJsonPath('data.landlord.id', $landlord->id)
            ->assertJsonCount(0, 'data.images');
    }

    public function test_tenant_cannot_view_ineligible_room_details_or_submit_an_inquiry_for_them(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        Sanctum::actingAs($tenant);

        $this->getJson("/api/rooms/{$room->id}")->assertForbidden();
        $this->postJson("/api/rooms/{$room->id}/inquiries", [
            'message' => 'I would like to rent this occupied room.',
        ])->assertUnprocessable()->assertJsonValidationErrors('room');

        $this->assertDatabaseMissing('inquiries', ['room_id' => $room->id, 'tenant_id' => $tenant->id]);
    }

    public function test_landlord_can_view_only_their_own_room_details(): void
    {
        $owner = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        $ownRoom = Room::factory()->create(['landlord_id' => $owner->id, 'status' => 'maintenance']);
        $otherRoom = Room::factory()->create(['landlord_id' => $otherLandlord->id, 'status' => 'available']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/rooms/{$ownRoom->id}")->assertOk()->assertJsonPath('data.id', $ownRoom->id);
        $this->getJson("/api/rooms/{$otherRoom->id}")->assertForbidden();
    }
}
