<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Room;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.business_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-11-14 16:05:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_business_calendar_rolls_over_at_philippine_midnight_while_timestamp_clock_stays_utc(): void
    {
        $this->assertSame('2026-11-15', BusinessTime::date());
        $this->assertSame('2026-11', BusinessTime::period());
        $this->assertSame('2026-11-14 16:05:00', now()->toDateTimeString());
    }

    public function test_inquiry_accepts_today_in_manila_even_while_utc_is_still_yesterday(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($tenant);

        $this->postJson("/api/rooms/{$room->id}/inquiries", [
            'message' => 'I would like to rent this room.',
            'desired_start_date' => '2026-11-15',
        ])->assertCreated()->assertJsonPath('data.desired_start_date', '2026-11-15');

        $this->postJson("/api/rooms/{$room->id}/inquiries", [
            'message' => 'I would like to rent this room next day.',
            'desired_start_date' => '2026-11-14',
        ])->assertUnprocessable()->assertJsonValidationErrors('desired_start_date');
    }

    public function test_landlord_can_block_a_room_starting_on_manila_today(): void
    {
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($landlord);

        $this->postJson("/api/rooms/{$room->id}/availability-blocks", [
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-15',
            'reason' => 'QA midnight boundary',
        ])->assertCreated()->assertJsonPath('block.reason', 'QA midnight boundary');
    }

    public function test_move_in_inspection_accepts_manila_today_and_keeps_recorded_timestamp_utc(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => '2026-11-15', 'monthly_rent' => 4500, 'status' => 'active',
            'active_room_id' => $room->id, 'agreement_status' => 'accepted',
        ]);

        Sanctum::actingAs($landlord);
        $this->postJson("/api/leases/{$lease->id}/inspections", [
            'kind' => 'move_in', 'inspection_date' => '2026-11-15', 'condition' => 'Clean and ready.',
        ])->assertOk();

        $inspection = $lease->inspections()->firstOrFail();
        $this->assertSame('2026-11-15', $inspection->inspection_date->toDateString());
        $this->assertSame('2026-11-14 16:05:00', $inspection->completed_at->utc()->toDateTimeString());
    }

    public function test_invoice_generator_uses_manila_month_at_utc_month_boundary(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => '2026-11-01', 'monthly_rent' => 4500, 'status' => 'active', 'active_room_id' => $room->id,
        ]);

        $result = app(InvoiceGenerator::class)->generate();

        $this->assertSame('2026-11', $result['period']);
        $this->assertSame(1, $result['created']);
        $invoice = $lease->invoices()->firstOrFail();
        $this->assertSame('2026-11', $invoice->billing_period);
        $this->assertSame('2026-12-05', $invoice->due_date->toDateString());
    }

    public function test_invoice_due_status_uses_manila_calendar_date(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => '2026-11-01', 'monthly_rent' => 100, 'status' => 'active', 'active_room_id' => $room->id,
        ]);
        $invoice = Invoice::create([
            'lease_id' => $lease->id, 'billing_period' => '2026-10', 'base_rent' => 100,
            'additional_charges_total' => 0, 'total_amount_due' => 100, 'amount_paid' => 0,
            'status' => 'unpaid', 'due_date' => '2026-11-14',
        ]);

        $invoice->refreshPaymentStatus();

        $this->assertSame(Invoice::OVERDUE, $invoice->fresh()->status);
    }
}
