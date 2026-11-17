<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_without_json_accept_header_returns_401_instead_of_login_redirect_error(): void
    {
        $this->get('/api/rooms')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_tenant_cannot_create_a_room(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant);

        $this->postJson('/api/rooms', [
            'room_number' => '101',
            'title' => 'Test Room',
            'location' => 'Manila',
            'price' => 5000,
        ])->assertForbidden();
    }

    public function test_landlord_cannot_update_another_landlords_room(): void
    {
        $owner = User::factory()->landlord()->create();
        $other = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $owner->id]);

        Sanctum::actingAs($other);
        $this->putJson("/api/rooms/{$room->id}", [
            'title' => 'Hijacked',
            'location' => 'Manila',
            'price' => 1,
            'room_number' => '999',
        ])->assertForbidden();
    }

    public function test_tenant_cannot_list_admin_users(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create());
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_cannot_create_new_staff_accounts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/users', [
            'name' => 'New Staff Member',
            'email' => 'new.staff@example.test',
            'role' => 'staff',
            'password' => 'password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_admin_cannot_assign_staff_role_to_an_account(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $tenant = User::factory()->tenant()->create();

        $this->patchJson("/api/admin/users/{$tenant->id}", ['role' => 'staff'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('tenant', $tenant->fresh()->role->name);
    }

    public function test_admin_room_listing_includes_landlord_identity_and_actual_room_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $landlord = User::factory()->landlord()->create(['name' => 'Overview Landlord']);
        Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/rooms?per_page=50')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $response->assertJsonFragment([
            'id' => $landlord->id,
            'name' => 'Overview Landlord',
            'email' => $landlord->email,
        ])->assertJsonFragment(['status' => 'available'])
            ->assertJsonFragment(['status' => 'occupied']);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }
}
