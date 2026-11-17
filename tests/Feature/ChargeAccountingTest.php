<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PendingCharge;
use App\Models\Payment;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function activeLease(User $landlord, User $tenant): Lease
    {
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);

        return Lease::create([
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'monthly_rent' => '1000.00',
            'status' => 'active',
            'active_room_id' => $room->id,
        ]);
    }

    public function test_period_scoped_utility_charge_is_idempotent_and_billed_once(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $lease = $this->activeLease($landlord, $tenant);
        $payload = [
            'category' => 'APPLIANCE',
            'utility_type' => 'electricity',
            'description' => 'November meter reading',
            'amount' => '18.75',
            'billing_period' => now()->format('Y-m'),
            'client_uuid' => (string) Str::uuid(),
        ];
        Sanctum::actingAs($landlord);

        $created = $this->postJson("/api/leases/{$lease->id}/charges", $payload)
            ->assertCreated()
            ->assertJsonPath('charge.utility_type', 'electricity')
            ->assertJsonPath('charge.billing_period', $payload['billing_period']);
        $chargeId = $created->json('charge.id');

        $this->postJson("/api/leases/{$lease->id}/charges", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('charge.id', $chargeId);
        $this->assertSame(1, PendingCharge::where('lease_id', $lease->id)->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'charge.created')->count());
        $this->assertSame(0, Notification::where('user_id', $landlord->id)->where('type', 'charge.created')->count());

        $generated = $this->postJson('/api/invoices/generate', ['period' => $payload['billing_period']])->assertOk()->json();
        $this->assertSame(1, $generated['created']);
        $invoice = $lease->invoices()->firstOrFail();
        $charge = PendingCharge::findOrFail($chargeId);
        $this->assertSame($invoice->id, $charge->invoice_id);
        $this->assertSame('1018.75', $invoice->total_amount_due);

        $this->postJson('/api/invoices/generate', ['period' => $payload['billing_period']])->assertOk()->assertJsonPath('created', 0);
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'invoice.new')->count());
        $this->assertSame(1, Notification::where('user_id', $landlord->id)->where('type', 'invoice.new')->count());
        $this->assertSame(1, $lease->invoices()->count());
        $this->assertSame(1, $invoice->charges()->count());
        $this->postJson("/api/charges/{$chargeId}/void")->assertUnprocessable();
        $this->assertSame('billed', $charge->fresh()->status);

        Sanctum::actingAs($tenant);
        $paymentPayload = [
            'amount' => '1018.75',
            'method' => 'gcash_simulated',
            'client_uuid' => (string) Str::uuid(),
        ];
        $paymentResponse = $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $paymentPayload)
            ->assertCreated()
            ->assertJsonPath('payment.transaction_status', 'succeeded')
            ->assertJsonPath('payment.method', 'gcash_simulated')
            ->assertJsonPath('invoice.status', 'Paid')
            ->assertJsonPath('invoice.balance', '0.00');
        $reference = $paymentResponse->json('payment.reference_number');
        $this->assertNotEmpty($reference);

        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $paymentPayload)
            ->assertCreated()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('payment.reference_number', $reference);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('1018.75', $invoice->fresh()->amount_paid);

        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('data.0.invoice_number', 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT));
        $this->get("/api/invoices/{$invoice->id}/pdf")->assertOk()
            ->assertSee('Electricity')
            ->assertSee('Additional charges subtotal: PHP 18.75');
        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->get("/api/payments/{$payment->id}/receipt.pdf")->assertOk()
            ->assertSee($reference);
    }

    public function test_landlord_cannot_charge_another_landlords_lease(): void
    {
        $landlord = User::factory()->landlord()->create();
        $owner = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $lease = $this->activeLease($owner, $tenant);
        Sanctum::actingAs($landlord);

        $this->postJson("/api/leases/{$lease->id}/charges", [
            'category' => 'OTHER',
            'amount' => '10.00',
            'billing_period' => now()->format('Y-m'),
        ])->assertForbidden();
        $this->assertSame(0, PendingCharge::count());
    }

    public function test_added_charge_and_invoice_adjustment_notify_rental_participants_only(): void
    {
        $admin = User::factory()->admin()->create();
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $lease = $this->activeLease($landlord, $tenant);
        $period = now()->format('Y-m');

        Sanctum::actingAs($landlord);
        $this->postJson('/api/invoices/generate', ['period' => $period])->assertOk()->assertJsonPath('created', 1);
        $this->postJson("/api/leases/{$lease->id}/charges", [
            'category' => 'APPLIANCE',
            'description' => 'Water bill',
            'amount' => '75.00',
            'billing_period' => $period,
            'client_uuid' => (string) Str::uuid(),
        ])->assertCreated();
        $this->postJson('/api/invoices/generate', ['period' => $period])->assertOk()->assertJsonPath('updated', 1);
        $this->postJson('/api/invoices/generate', ['period' => $period])->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'charge.created')->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'invoice.new')->count());
        $this->assertSame(1, Notification::where('user_id', $tenant->id)->where('type', 'invoice.updated')->count());
        $this->assertSame(1, Notification::where('user_id', $landlord->id)->where('type', 'invoice.new')->count());
        $this->assertSame(1, Notification::where('user_id', $landlord->id)->where('type', 'invoice.updated')->count());
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
    }

    public function test_charge_cannot_be_added_to_a_paid_period(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $lease = $this->activeLease($landlord, $tenant);
        Invoice::create([
            'lease_id' => $lease->id,
            'billing_period' => now()->format('Y-m'),
            'base_rent' => '1000.00',
            'additional_charges_total' => '0.00',
            'total_amount_due' => '1000.00',
            'amount_paid' => '1000.00',
            'status' => Invoice::PAID,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        Sanctum::actingAs($landlord);

        $this->postJson("/api/leases/{$lease->id}/charges", [
            'category' => 'OTHER',
            'description' => 'Late utility adjustment',
            'amount' => '10.00',
            'billing_period' => now()->format('Y-m'),
        ])->assertUnprocessable();
        $this->assertSame(0, PendingCharge::count());
    }
}
