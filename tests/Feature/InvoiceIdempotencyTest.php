<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Invoice;
use App\Models\PendingCharge;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_invoice_generation_is_safe_to_rerun(): void
    {
        $admin = User::factory()->admin()->create();
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id, 'price' => 5000]);

        $lease = Lease::create([
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'monthly_rent' => 5000,
            'status' => 'active',
            'active_room_id' => $room->id,
        ]);

        PendingCharge::create([
            'lease_id' => $lease->id,
            'category' => 'CANTEEN',
            'description' => 'Meals',
            'amount' => 250.50,
            'status' => 'pending',
            'billing_period' => now()->format('Y-m'),
        ]);
        foreach ([['Electricity', '0.10', 'electricity'], ['Water', '0.20', 'water']] as [$description, $amount, $utilityType]) {
            PendingCharge::create([
                'lease_id' => $lease->id,
                'category' => 'APPLIANCE',
                'description' => $description,
                'amount' => $amount,
                'utility_type' => $utilityType,
                'status' => 'pending',
                'billing_period' => now()->format('Y-m'),
            ]);
        }
        $nextPeriod = now()->addMonth()->format('Y-m');
        PendingCharge::create([
            'lease_id' => $lease->id,
            'category' => 'APPLIANCE',
            'description' => 'Next month Wi-Fi',
            'amount' => '50.05',
            'utility_type' => 'wifi',
            'status' => 'pending',
            'billing_period' => $nextPeriod,
        ]);

        $period = now()->format('Y-m');
        Sanctum::actingAs($landlord);

        $firstResponse = $this->postJson('/api/invoices/generate', ['period' => $period])->assertOk();
        $secondResponse = $this->postJson('/api/invoices/generate', ['period' => $period])->assertOk();
        $first = $firstResponse->json();
        $second = $secondResponse->json();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertEquals(1, $lease->invoices()->where('billing_period', $period)->count());

        $invoice = $lease->invoices()->first();
        $this->assertEquals('5250.80', $invoice->total_amount_due);
        $this->assertSame(3, $invoice->charges()->count());
        $this->assertSame(1, PendingCharge::where('status', 'pending')->count());
        $this->assertSame($invoice->id, PendingCharge::where('description', 'Electricity')->value('invoice_id'));
        $this->assertDatabaseHas('notifications', ['user_id' => $tenant->id, 'type' => 'invoice.new']);
        $this->assertDatabaseHas('notifications', ['user_id' => $landlord->id, 'type' => 'invoice.new']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id, 'type' => 'system.invoice']);
        $this->assertSame(1, \App\Models\Notification::where('user_id', $tenant->id)->where('type', 'invoice.new')->count());
        $this->assertSame(1, \App\Models\Notification::where('user_id', $landlord->id)->where('type', 'invoice.new')->count());

        $nextResult = $this->postJson('/api/invoices/generate', ['period' => $nextPeriod])->assertOk()->json();
        $this->assertSame(1, $nextResult['created']);
        $nextInvoice = $lease->invoices()->where('billing_period', $nextPeriod)->firstOrFail();
        $this->assertSame('5050.05', $nextInvoice->total_amount_due);
        $this->assertSame(0, PendingCharge::where('status', 'pending')->count());
        $this->assertSame($nextInvoice->id, PendingCharge::where('description', 'Next month Wi-Fi')->value('invoice_id'));
    }

    public function test_landlord_date_generation_uses_selected_month_and_only_own_active_leases(): void
    {
        $landlord = User::factory()->landlord()->create();
        $otherLandlord = User::factory()->landlord()->create();
        $ownTenant = User::factory()->tenant()->create();
        $otherTenant = User::factory()->tenant()->create();
        $ownRoom = Room::factory()->create(['landlord_id' => $landlord->id, 'price' => 4200]);
        $otherRoom = Room::factory()->create(['landlord_id' => $otherLandlord->id, 'price' => 6100]);

        $ownLease = Lease::create([
            'room_id' => $ownRoom->id,
            'tenant_id' => $ownTenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => '2026-11-01',
            'monthly_rent' => 4200,
            'status' => 'active',
            'active_room_id' => $ownRoom->id,
        ]);
        $otherLease = Lease::create([
            'room_id' => $otherRoom->id,
            'tenant_id' => $otherTenant->id,
            'landlord_id' => $otherLandlord->id,
            'start_date' => '2026-11-01',
            'monthly_rent' => 6100,
            'status' => 'active',
            'active_room_id' => $otherRoom->id,
        ]);

        Sanctum::actingAs($landlord);

        $this->postJson('/api/invoices/generate', ['period' => '2026-11-18'])
            ->assertOk()
            ->assertJsonPath('period', '2026-11')
            ->assertJsonPath('created', 1);

        $this->assertSame(1, $ownLease->invoices()->where('billing_period', '2026-11')->count());
        $this->assertSame(0, $otherLease->invoices()->where('billing_period', '2026-11')->count());

        $otherInvoice = Invoice::create([
            'lease_id' => $otherLease->id,
            'billing_period' => '2026-11',
            'base_rent' => '6100.00',
            'additional_charges_total' => '0.00',
            'total_amount_due' => '6100.00',
            'amount_paid' => '0.00',
            'status' => Invoice::UNPAID,
            'due_date' => '2026-12-05',
        ]);
        $this->getJson('/api/invoices/'.$otherInvoice->id)->assertForbidden();
        $this->postJson('/api/invoices/'.$otherInvoice->id.'/payments', [
            'amount' => '100.00',
            'method' => 'cash',
        ])->assertForbidden();
        $this->assertSame('0.00', $otherInvoice->fresh()->amount_paid);
        $this->assertSame(0, $otherInvoice->payments()->count());

        // A different selected day in the same month must not create a duplicate invoice.
        $this->postJson('/api/invoices/generate', ['period' => '2026-11-27'])
            ->assertOk()
            ->assertJsonPath('period', '2026-11')
            ->assertJsonPath('created', 0);
        $this->assertSame(1, $ownLease->invoices()->where('billing_period', '2026-11')->count());
    }

    public function test_invoice_generation_rejects_impossible_selected_dates(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord);

        $this->postJson('/api/invoices/generate', ['period' => '2026-11-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->assertDatabaseCount('invoices', 0);
    }
}
