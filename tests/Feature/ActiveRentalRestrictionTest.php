<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Lease;
use App\Models\Room;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveRentalRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function activeLease(User $landlord, User $tenant, Room $room, ?string $endDate = null): Lease
    {
        return Lease::create([
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => BusinessTime::date(),
            'end_date' => $endDate,
            'monthly_rent' => 4000,
            'status' => 'active',
            'active_room_id' => $room->id,
        ]);
    }

    public function test_tenant_with_active_lease_cannot_submit_another_application(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $occupied = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $available = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $this->activeLease($landlord, $tenant, $occupied);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/rooms/{$available->id}/inquiries", [
            'message' => 'I would like to rent this room.',
        ])->assertUnprocessable()->assertJsonValidationErrors('rental')
            ->assertJsonPath('errors.rental.0', 'You already have an active rental. You can apply for another room after your current lease has officially ended.');

        $this->assertDatabaseMissing('inquiries', ['room_id' => $available->id, 'tenant_id' => $tenant->id]);
    }

    public function test_active_status_remains_blocking_after_contract_end_date_until_moveout_ends_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $occupied = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $available = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $this->activeLease($landlord, $tenant, $occupied, BusinessTime::today()->subDay()->toDateString());

        Sanctum::actingAs($tenant);
        $this->postJson("/api/rooms/{$available->id}/inquiries", [
            'message' => 'I would like to rent this room.',
        ])->assertUnprocessable()->assertJsonValidationErrors('rental');
    }

    public function test_pending_application_does_not_create_a_lease_or_prevent_normal_applications(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $first = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $second = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        Inquiry::factory()->create(['room_id' => $first->id, 'tenant_id' => $tenant->id, 'status' => Inquiry::PENDING]);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/rooms/{$second->id}/inquiries", [
            'message' => 'I would like to rent this other room.',
        ])->assertCreated();

        $this->assertSame(0, Lease::where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, Inquiry::where('tenant_id', $tenant->id)->where('status', Inquiry::PENDING)->count());
    }

    public function test_tenant_can_apply_after_lease_is_officially_ended(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $oldRoom = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $available = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        Lease::create([
            'room_id' => $oldRoom->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => BusinessTime::today()->subMonth()->toDateString(),
            'end_date' => BusinessTime::today()->subDay()->toDateString(),
            'monthly_rent' => 4000,
            'status' => 'ended',
            'active_room_id' => null,
        ]);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/rooms/{$available->id}/inquiries", [
            'message' => 'I would like to rent this room.',
        ])->assertCreated();
    }

    public function test_approval_and_acceptance_are_blocked_if_tenant_has_active_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $activeRoom = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $offerRoom = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $this->activeLease($landlord, $tenant, $activeRoom);
        $pending = Inquiry::factory()->create(['room_id' => $offerRoom->id, 'tenant_id' => $tenant->id, 'status' => Inquiry::PENDING]);

        Sanctum::actingAs($landlord);
        $this->postJson("/api/inquiries/{$pending->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('rental');
        $this->assertSame(Inquiry::PENDING, $pending->fresh()->status);

        $approved = Inquiry::factory()->create([
            'room_id' => $offerRoom->id,
            'tenant_id' => $tenant->id,
            'status' => Inquiry::APPROVED,
            'offer_expires_at' => now()->addHour(),
        ]);
        Sanctum::actingAs($tenant);
        $this->postJson("/api/inquiries/{$approved->id}/accept")
            ->assertUnprocessable()->assertJsonValidationErrors('rental');
        $this->assertSame(Inquiry::APPROVED, $approved->fresh()->status);
        $this->assertSame(1, Lease::where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }

    public function test_direct_lease_creation_cannot_give_tenant_a_second_active_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $occupied = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        $available = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'available']);
        $this->activeLease($landlord, $tenant, $occupied);

        Sanctum::actingAs($landlord);
        $this->postJson('/api/leases', [
            'room_id' => $available->id,
            'tenant_id' => $tenant->id,
            'start_date' => BusinessTime::date(),
            'monthly_rent' => 4000,
        ])->assertUnprocessable()->assertJsonValidationErrors('rental');

        $this->assertSame(1, Lease::where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }
}
