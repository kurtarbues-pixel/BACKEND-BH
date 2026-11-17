<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Room;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RentalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_blocks_dates_and_tenant_sees_them_unavailable(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $start = now()->addDays(4)->toDateString();
        $end = now()->addDays(6)->toDateString();

        Sanctum::actingAs($landlord);
        $this->postJson("/api/rooms/{$room->id}/availability-blocks", [
            'start_date' => $start, 'end_date' => $end, 'reason' => 'Repairs',
        ])->assertCreated();

        Sanctum::actingAs($tenant);
        $calendar = $this->getJson("/api/rooms/{$room->id}/availability-calendar?start_date={$start}&end_date={$end}")->assertOk();
        $calendar->assertJsonPath('days.0.available', false)->assertJsonPath('days.0.reason', 'Repairs');
        $this->postJson("/api/rooms/{$room->id}/inquiries", [
            'message' => 'I would like to rent this room.', 'desired_start_date' => $start,
        ])->assertUnprocessable();
    }

    public function test_tenant_accepts_terms_movein_and_moveout_controls_room_and_damage_invoice(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);

        Sanctum::actingAs($landlord);
        $response = $this->postJson('/api/leases', [
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => BusinessTime::date(), 'monthly_rent' => 4500,
        ])->assertCreated();
        $leaseId = $response->json('data.id');
        $this->assertSame('occupied', $room->fresh()->status);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/leases/{$leaseId}/agreement/accept", ['agreement_accepted' => true])
            ->assertOk()->assertJsonPath('lease.agreement_status', 'accepted');
        $pdfResponse = $this->get("/api/leases/{$leaseId}/agreement.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdfResponse->getContent());

        Sanctum::actingAs($landlord);
        $this->postJson("/api/leases/{$leaseId}/inspections", [
            'kind' => 'move_in', 'inspection_date' => BusinessTime::date(), 'condition' => 'Clean and ready.',
        ])->assertOk();
        $this->assertSame('occupied', $room->fresh()->status);

        $otherTenant = User::factory()->tenant()->create();
        $this->postJson('/api/leases', [
            'room_id' => $room->id, 'tenant_id' => $otherTenant->id,
            'start_date' => now()->addDays(10)->toDateString(), 'monthly_rent' => 4500,
        ])->assertUnprocessable();

        $this->postJson("/api/leases/{$leaseId}/inspections", [
            'kind' => 'move_out', 'inspection_date' => BusinessTime::today()->addDay()->toDateString(),
            'condition' => 'Door damaged.', 'damages_amount' => '850.00', 'damage_description' => 'Door repair',
        ])->assertUnprocessable()->assertJsonValidationErrors('inspection_date');
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame('occupied', $room->fresh()->status);

        $this->postJson("/api/leases/{$leaseId}/inspections", [
            'kind' => 'move_out', 'inspection_date' => BusinessTime::date(),
            'condition' => 'Door damaged.', 'damages_amount' => '850.00', 'damage_description' => 'Door repair',
        ])->assertOk()->assertJsonPath('lease.status', 'ended');
        $this->assertSame('available', $room->fresh()->status);
        $this->assertDatabaseHas('pending_charges', ['lease_id' => $leaseId, 'category' => 'OTHER', 'status' => 'billed']);
        $this->assertDatabaseHas('invoices', ['lease_id' => $leaseId, 'total_amount_due' => '850.00']);
    }

    public function test_tenant_cannot_record_an_inspection_for_another_landlords_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => now()->toDateString(), 'monthly_rent' => 4000, 'status' => 'active',
            'agreement_status' => 'accepted', 'agreement_accepted_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->tenant()->create());
        $this->postJson("/api/leases/{$lease->id}/inspections", [
            'kind' => 'move_in', 'inspection_date' => now()->toDateString(), 'condition' => 'Fine',
        ])->assertForbidden();
    }

    public function test_admin_can_review_lease_and_record_inspections_after_tenant_acceptance(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $admin = User::factory()->admin()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => now()->toDateString(), 'monthly_rent' => 4000, 'status' => 'active',
            'agreement_status' => 'pending',
        ]);

        Sanctum::actingAs($tenant);
        $this->postJson("/api/leases/{$lease->id}/agreement/accept", ['agreement_accepted' => true])->assertOk();

        Sanctum::actingAs($admin);
        $this->getJson("/api/rooms/{$room->id}/availability-calendar?start_date=".now()->toDateString().'&end_date='.now()->addDays(2)->toDateString())->assertOk();
        $this->get("/api/leases/{$lease->id}/agreement.pdf")->assertOk();
        $this->postJson("/api/leases/{$lease->id}/inspections", [
            'kind' => 'move_in', 'inspection_date' => now()->toDateString(), 'condition' => 'Ready for occupancy.',
        ])->assertOk();
    }

    public function test_landlord_cannot_block_or_reopen_another_landlords_room(): void
    {
        $owner = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $owner->id]);

        Sanctum::actingAs($otherLandlord);
        $this->postJson("/api/rooms/{$room->id}/availability-blocks", [
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $block = $this->postJson("/api/rooms/{$room->id}/availability-blocks", [
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('block.id');

        Sanctum::actingAs($otherLandlord);
        $this->deleteJson("/api/rooms/{$room->id}/availability-blocks/{$block}")->assertForbidden();
    }

    public function test_landlord_cannot_mark_a_leased_room_available_before_moveout(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id, 'status' => 'occupied']);
        Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => now()->toDateString(), 'monthly_rent' => 4000, 'status' => 'active',
            'active_room_id' => $room->id,
        ]);

        Sanctum::actingAs($landlord);
        $this->patchJson("/api/rooms/{$room->id}/availability", ['status' => 'available'])
            ->assertUnprocessable();
        $this->assertSame('occupied', $room->fresh()->status);
    }
}
