<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SimulatedPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_updates_invoice_and_returns_receipt_data(): void
    {
        $admin = User::factory()->admin()->create();
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);

        $response = $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '100.00'))
            ->assertCreated()
            ->assertJsonPath('payment.transaction_status', 'succeeded')
            ->assertJsonPath('invoice.status', 'Paid')
            ->assertJsonPath('invoice.balance', '0.00');

        $this->assertNotEmpty($response->json('payment.reference_number'));
        $this->assertSame('100.00', $invoice->fresh()->amount_paid);
        $this->assertDatabaseCount('payments', 1);
        $landlordId = $invoice->lease()->value('landlord_id');
        $this->assertDatabaseHas('notifications', ['user_id' => $tenant->id, 'type' => 'payment.received']);
        $this->assertDatabaseHas('notifications', ['user_id' => $landlordId, 'type' => 'payment.received']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id, 'type' => 'system.payment']);
    }

    public function test_partial_simulated_payment_updates_only_the_paid_amount_and_keeps_balance_due(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);

        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '35.25'))
            ->assertCreated()
            ->assertJsonPath('invoice.status', 'Partial')
            ->assertJsonPath('invoice.amount_paid', '35.25')
            ->assertJsonPath('invoice.balance', '64.75');

        $this->assertSame('35.25', $invoice->fresh()->amount_paid);
        $this->assertSame('64.75', $invoice->fresh()->remainingBalance());
    }

    public function test_landlord_recorded_payment_notifies_tenant_and_landlord_but_not_admin(): void
    {
        $admin = User::factory()->admin()->create();
        [$tenant, $invoice] = $this->makeInvoice();
        $landlordId = $invoice->lease()->value('landlord_id');

        Sanctum::actingAs(User::findOrFail($landlordId));
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => '25.00', 'method' => 'cash'])->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $tenant->id, 'type' => 'payment.received']);
        $this->assertDatabaseHas('notifications', ['user_id' => $landlordId, 'type' => 'payment.received']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id, 'type' => 'system.payment']);
    }

    public function test_failed_simulation_does_not_change_invoice_balance(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);

        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '35.00', ['simulate_failure' => true]))
            ->assertOk()
            ->assertJsonPath('payment.transaction_status', 'failed')
            ->assertJsonPath('invoice.balance', '100.00');

        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('notifications', ['type' => 'system.payment']);
    }

    public function test_retry_with_same_client_reference_does_not_duplicate_payment(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);
        $payload = $this->paymentPayload($invoice, '30.00');

        $first = $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $payload)->assertCreated();
        $second = $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $payload)->assertCreated()->assertJsonPath('idempotent', true);

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('30.00', $invoice->fresh()->amount_paid);
    }

    public function test_overpayment_and_amount_with_more_than_two_decimal_places_are_rejected(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);

        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '100.01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '10.001'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
    }

    public function test_tenant_cannot_pay_another_tenants_invoice(): void
    {
        [$owner, $invoice] = $this->makeInvoice();
        Sanctum::actingAs(User::factory()->tenant()->create());

        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '10.00'))
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
    }

    public function test_payment_routes_enforce_tenant_and_landlord_roles(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs(User::factory()->landlord()->create());
        $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '10.00'))->assertForbidden();

        Sanctum::actingAs($tenant);
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 10, 'method' => 'cash'])->assertForbidden();

        Sanctum::actingAs(User::factory()->landlord()->create());
        $this->getJson('/api/invoices')->assertOk();
    }

    public function test_payment_history_contains_payment_and_receipt_reference(): void
    {
        [$tenant, $invoice] = $this->makeInvoice();
        Sanctum::actingAs($tenant);
        $paymentResponse = $this->postJson("/api/invoices/{$invoice->id}/simulated-payments", $this->paymentPayload($invoice, '25.00'))->assertCreated();
        $reference = $paymentResponse->json('payment.reference_number');

        $this->getJson('/api/invoices')->assertOk()->assertJsonFragment([
            'reference_number' => $reference,
            'transaction_status' => 'succeeded',
        ]);
    }

    private function makeInvoice(): array
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        $lease = Lease::create([
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'monthly_rent' => 100,
            'status' => 'active',
            'active_room_id' => $room->id,
        ]);
        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'billing_period' => now()->format('Y-m'),
            'base_rent' => 80,
            'additional_charges_total' => 20,
            'total_amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'unpaid',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        return [$tenant, $invoice];
    }

    private function paymentPayload(Invoice $invoice, string $amount, array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'method' => 'gcash_simulated',
            'client_uuid' => (string) Str::uuid(),
        ], $overrides);
    }
}
