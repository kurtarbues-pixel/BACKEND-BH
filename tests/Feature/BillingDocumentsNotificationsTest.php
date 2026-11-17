<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PendingCharge;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingDocumentsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceData(): array
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id, 'room_number' => 'B12', 'title' => 'Garden Room']);
        $lease = Lease::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'landlord_id' => $landlord->id,
            'start_date' => now()->startOfMonth()->toDateString(), 'monthly_rent' => 5000, 'status' => 'active', 'active_room_id' => $room->id,
        ]);
        $invoice = Invoice::create([
            'lease_id' => $lease->id, 'billing_period' => now()->format('Y-m'), 'base_rent' => 5000,
            'additional_charges_total' => 350, 'total_amount_due' => 5350, 'amount_paid' => 1000,
            'status' => 'partial', 'due_date' => now()->addDays(5)->toDateString(),
        ]);
        PendingCharge::create(['lease_id' => $lease->id, 'invoice_id' => $invoice->id, 'category' => 'APPLIANCE', 'description' => 'Fan repair', 'amount' => 350, 'status' => 'billed', 'utility_type' => 'electricity', 'billing_period' => $invoice->billing_period]);
        return [$landlord, $tenant, $lease, $invoice];
    }

    public function test_invoice_pdf_is_authorized_and_includes_rent_charges_and_payment_details(): void
    {
        [$landlord, $tenant, , $invoice] = $this->invoiceData();
        Payment::create([
            'invoice_id' => $invoice->id, 'recorded_by' => $landlord->id, 'amount' => 1000, 'method' => 'cash',
            'transaction_status' => 'succeeded', 'reference_number' => 'BE-TEST-001', 'paid_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($tenant);
        $response = $this->get("/api/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringContainsString('Fan repair', $response->getContent());
        $this->assertStringContainsString('Utility - Electricity', $response->getContent());
        $this->assertStringContainsString('Period: '.$invoice->billing_period, $response->getContent());
        $this->assertStringContainsString('Additional charges subtotal: PHP 350.00', $response->getContent());
        $this->assertStringContainsString('Invoice total - base rent plus additional charges: PHP 5,350.00', $response->getContent());
        $this->assertStringContainsString('Invoice number: INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT), $response->getContent());
        $this->assertStringContainsString('BE-TEST-001', $response->getContent());

        Sanctum::actingAs(User::factory()->tenant()->create());
        $this->getJson("/api/invoices/{$invoice->id}/pdf")->assertForbidden();
    }

    public function test_only_successful_recorded_payments_have_downloadable_receipts(): void
    {
        [$landlord, $tenant, , $invoice] = $this->invoiceData();
        $success = Payment::create([
            'invoice_id' => $invoice->id, 'recorded_by' => $landlord->id, 'amount' => 1000, 'method' => 'cash',
            'transaction_status' => 'succeeded', 'reference_number' => 'BE-RECEIPT-OK', 'paid_at' => now(),
        ]);
        $failed = Payment::create([
            'invoice_id' => $invoice->id, 'recorded_by' => $tenant->id, 'amount' => 100, 'method' => 'gcash_simulated',
            'transaction_status' => 'failed', 'reference_number' => 'BE-RECEIPT-FAIL', 'paid_at' => null,
        ]);
        Sanctum::actingAs($tenant);
        $this->get("/api/payments/{$success->id}/receipt.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/payments/{$failed->id}/receipt.pdf")->assertNotFound();
        Sanctum::actingAs(User::factory()->landlord()->create());
        $this->getJson("/api/payments/{$success->id}/receipt.pdf")->assertForbidden();
    }

    public function test_notifications_are_private_and_marking_read_is_scoped_to_the_user(): void
    {
        $tenant = User::factory()->tenant()->create();
        $other = User::factory()->tenant()->create();
        $mine = Notification::create(['user_id' => $tenant->id, 'type' => 'invoice.new', 'title' => 'New invoice', 'body' => 'Invoice ready.']);
        $theirs = Notification::create(['user_id' => $other->id, 'type' => 'message', 'title' => 'Private message', 'body' => 'Hello.']);
        Sanctum::actingAs($tenant);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->patchJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->patchJson("/api/notifications/{$mine->id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_admin_landlord_and_tenant_notification_history_and_bulk_read_are_account_scoped(): void
    {
        $accounts = [
            User::factory()->admin()->create(),
            User::factory()->landlord()->create(),
            User::factory()->tenant()->create(),
        ];
        $notifications = collect($accounts)->map(fn (User $account) => Notification::create([
            'user_id' => $account->id,
            'type' => 'qa.privacy',
            'title' => 'Private account notice',
            'body' => 'This notification belongs to this account.',
        ]));

        foreach ($accounts as $index => $account) {
            Sanctum::actingAs($account);
            $response = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame($notifications[$index]->id, $response->json('data.0.id'));

            $otherNotification = $notifications[($index + 1) % count($accounts)];
            $otherWasRead = $otherNotification->fresh()->read_at !== null;
            $this->patchJson("/api/notifications/{$otherNotification->id}/read")->assertNotFound();
            $this->postJson('/api/notifications/read-all')->assertOk();
            $this->assertNotNull($notifications[$index]->fresh()->read_at);
            $this->assertSame($otherWasRead, $otherNotification->fresh()->read_at !== null);
        }

        foreach ($notifications as $notification) {
            $this->assertNotNull($notification->fresh()->read_at);
        }
    }

    public function test_lease_end_reminders_are_created_for_participants_once(): void
    {
        $this->travelTo(now()->startOfDay());
        [$landlord, $tenant, $lease] = $this->invoiceData();
        $lease->update(['end_date' => now()->addDays(7)->toDateString()]);
        $this->artisan('leases:notify-upcoming-end')->assertSuccessful();
        $this->artisan('leases:notify-upcoming-end')->assertSuccessful();
        $this->assertSame(2, Notification::where('type', 'lease.reminder')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $tenant->id, 'type' => 'lease.reminder']);
        $this->assertDatabaseHas('notifications', ['user_id' => $landlord->id, 'type' => 'lease.reminder']);
    }
}
