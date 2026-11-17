<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\LeaseInspection;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceMessagingReviewTest extends TestCase
{
    use RefreshDatabase;

    private function lease(User $landlord, User $tenant, Room $room, string $status = 'active'): Lease
    {
        return Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => $status === 'ended' ? now()->subDay()->toDateString() : null,
            'monthly_rent' => 4000, 'status' => $status, 'active_room_id' => $status === 'active' ? $room->id : null,
        ]);
    }

    public function test_tenant_submits_maintenance_and_only_property_landlord_can_update_it(): void
    {
        Storage::fake('public');
        $landlord = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = $this->lease($landlord, $tenant, $room);

        Sanctum::actingAs($tenant);
        $response = $this->postJson('/api/maintenance-requests', [
            'lease_id' => $lease->id, 'title' => 'Leaking tap', 'description' => 'Water is leaking under the sink.',
            'photo' => UploadedFile::fake()->createWithContent(
                'leak.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII=')
            ),
        ])->assertCreated();
        $requestId = $response->json('id');
        $this->assertDatabaseHas('maintenance_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->assertSame(1, Notification::where('user_id', $landlord->id)->where('type', 'maintenance')->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'maintenance')->count());

        Sanctum::actingAs($otherLandlord);
        $this->getJson('/api/maintenance-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/maintenance-requests/{$requestId}", ['status' => 'in_progress'])->assertNotFound();

        Sanctum::actingAs($landlord);
        $this->patchJson("/api/maintenance-requests/{$requestId}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('status', 'in_progress');
        $this->patchJson("/api/maintenance-requests/{$requestId}", ['status' => 'resolved'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertSame(3, Notification::where('user_id', $tenant->id)->where('type', 'maintenance')->count());
        $this->assertSame(3, Notification::where('user_id', $landlord->id)->where('type', 'maintenance')->count());
    }

    public function test_tenant_can_replace_and_remove_photo_on_own_pending_request(): void
    {
        Storage::fake('public');
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = $this->lease($landlord, $tenant, $room);
        $oldPath = 'maintenance/'.$lease->id.'/old-proof.jpg';
        Storage::disk('public')->put($oldPath, 'old photo');
        $request = MaintenanceRequest::create([
            'lease_id' => $lease->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'title' => 'Leaking tap', 'description' => 'Water is under the sink.', 'photo_path' => $oldPath,
        ]);

        Sanctum::actingAs($tenant);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII=');
        $this->post("/api/maintenance-requests/{$request->id}", [
            '_method' => 'PATCH', 'photo' => UploadedFile::fake()->createWithContent('replacement.png', $png),
        ])->assertOk()->assertJsonPath('photo_url', Storage::disk('public')->url(MaintenanceRequest::findOrFail($request->id)->photo_path));

        $replacementPath = MaintenanceRequest::findOrFail($request->id)->photo_path;
        $this->assertNotSame($oldPath, $replacementPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($replacementPath);
        $this->getJson('/api/maintenance-requests')->assertOk()
            ->assertJsonPath('data.0.photo_path', $replacementPath)
            ->assertJsonPath('data.0.photo_url', Storage::disk('public')->url($replacementPath));

        $this->patchJson("/api/maintenance-requests/{$request->id}", ['remove_photo' => true])
            ->assertOk()->assertJsonPath('photo_path', null)->assertJsonPath('photo_url', null);
        Storage::disk('public')->assertMissing($replacementPath);
        $this->getJson('/api/maintenance-requests')->assertOk()->assertJsonPath('data.0.photo_path', null)->assertJsonPath('data.0.photo_url', null);
    }

    public function test_photo_edits_are_private_pending_only_and_validate_uploads(): void
    {
        Storage::fake('public');
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $otherTenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = $this->lease($landlord, $tenant, $room);
        $savedPath = 'maintenance/'.$lease->id.'/evidence.png';
        Storage::disk('public')->put($savedPath, 'evidence');
        $request = MaintenanceRequest::create([
            'lease_id' => $lease->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'title' => 'Broken window', 'description' => 'Window latch is broken.', 'photo_path' => $savedPath,
        ]);

        Sanctum::actingAs($otherTenant);
        $this->patchJson("/api/maintenance-requests/{$request->id}", ['remove_photo' => true])->assertNotFound();

        Sanctum::actingAs($landlord);
        $this->patchJson("/api/maintenance-requests/{$request->id}", ['status' => 'in_progress'])->assertOk();
        Sanctum::actingAs($tenant);
        $this->patchJson("/api/maintenance-requests/{$request->id}", ['remove_photo' => true])->assertUnprocessable();
        $this->post("/api/maintenance-requests/{$request->id}", [
            '_method' => 'PATCH', 'photo' => UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain'),
        ])->assertUnprocessable();
        $this->post("/api/maintenance-requests/{$request->id}", [
            '_method' => 'PATCH', 'photo' => UploadedFile::fake()->create('too-large.jpg', 4097, 'image/jpeg'),
        ])->assertUnprocessable();

        $this->assertDatabaseHas('maintenance_requests', ['id' => $request->id, 'photo_path' => $savedPath, 'status' => 'in_progress']);
        Storage::disk('public')->assertExists($savedPath);
    }

    public function test_tenant_and_landlord_can_message_only_within_their_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $otherTenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = $this->lease($landlord, $tenant, $room);

        Sanctum::actingAs($tenant);
        $conversation = $this->postJson("/api/leases/{$lease->id}/conversation")->assertOk()->json('conversation.id');
        $this->postJson("/api/conversations/{$conversation}/messages", ['body' => 'Hello, the tap is leaking.'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $landlord->id, 'type' => 'message']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $tenant->id, 'type' => 'message']);

        Sanctum::actingAs($otherTenant);
        $this->getJson("/api/conversations/{$conversation}")->assertNotFound();

        Sanctum::actingAs($landlord);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 1);
        $this->getJson("/api/conversations/{$conversation}")->assertOk()->assertJsonCount(1, 'messages');
        $this->postJson("/api/conversations/{$conversation}/messages", ['body' => 'Thanks, I will send maintenance tomorrow.'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $tenant->id, 'type' => 'message']);
    }

    public function test_only_completed_rentals_can_review_and_admin_moderates_public_reviews(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $admin = User::factory()->admin()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $lease = $this->lease($landlord, $tenant, $room, 'ended');
        LeaseInspection::create([
            'lease_id' => $lease->id, 'recorded_by' => $landlord->id, 'kind' => 'move_out',
            'inspection_date' => now()->subDay()->toDateString(), 'condition' => 'Clean and returned.',
            'status' => 'completed', 'completed_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($tenant);
        $this->getJson('/api/reviews/mine')->assertOk()->assertJsonPath('data.0.lease.inspections.0.status', 'completed');
        $response = $this->postJson("/api/leases/{$lease->id}/reviews", ['rating' => 5, 'review' => 'The room was clean and the landlord was helpful.'])->assertCreated();
        $reviewId = $response->json('id');
        $this->postJson("/api/leases/{$lease->id}/reviews", ['rating' => 4, 'review' => 'Another review for the same rental.'])->assertUnprocessable();
        $this->getJson("/api/rooms/{$room->id}/reviews")->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/reviews/{$reviewId}", ['status' => 'approved'])->assertOk();
        $this->getJson("/api/rooms/{$room->id}/reviews")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/rooms")->assertOk()->assertJsonFragment(['average_rating' => '5.0', 'review_count' => 1]);
    }
}
