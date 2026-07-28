<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'username' => 'ledger_admin',
            'password' => bcrypt('password'),
            'full_name' => 'Ledger Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);
        ShiftSession::create([
            'user_id' => $this->user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
        ]);
        $type = RoomType::create([
            'type_name' => 'Ledger Room',
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
        $this->room = Room::create([
            'room_number' => 'L01',
            'room_type_id' => $type->id,
            'status' => 'vacant',
        ]);
    }

    public function test_verified_future_cash_deposit_uses_received_date_and_appears_in_both_reports(): void
    {
        $booking = $this->booking(30, 1000);
        $payment = app(PaymentService::class)->record([
            'received_at' => now(),
            'payer_name' => 'Advance Payer',
            'payer_contact' => '09170000000',
            'payment_method_code' => 'cash',
            'amount' => 400,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 400]);

        $response = $this->actingAs($this->user)->get(route('reports.front_desk', [
            'from' => now()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ]));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame($payment->receipt_number, $props['collections'][0]['receipt_number']);
        $this->assertSame($booking->booking_ref, $props['advanceBookings'][0]['booking_ref']);
        $this->assertEquals(400.0, $props['summary']['gross']);
        $this->assertEquals(400.0, $booking->fresh()->amount_paid);
        $this->assertEquals('partial', $booking->fresh()->payment_status);
    }

    public function test_digital_payment_is_excluded_until_verified_then_appears_immediately(): void
    {
        $booking = $this->booking(60, 1500);
        $payment = app(PaymentService::class)->record([
            'received_at' => now(),
            'payer_name' => 'GCash Payer',
            'payment_method_code' => 'gcash',
            'reference_number' => 'GCASH-UNIQUE-001',
            'amount' => 750,
            'payment_type' => 'deposit',
            'status' => 'pending',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 750]);

        $pendingResponse = $this->actingAs($this->user)->get(route('reports.front_desk'));
        $pendingProps = $pendingResponse->viewData('page')['props'];
        $this->assertCount(0, $pendingProps['collections']);
        $this->assertSame($payment->receipt_number, $pendingProps['pendingPayments'][0]['receipt_number']);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);

        app(PaymentService::class)->verify($payment, $this->user->id);

        $verifiedResponse = $this->actingAs($this->user)->get(route('reports.front_desk'));
        $verifiedProps = $verifiedResponse->viewData('page')['props'];
        $this->assertSame($payment->receipt_number, $verifiedProps['collections'][0]['receipt_number']);
        $this->assertEquals(750.0, $booking->fresh()->amount_paid);
    }

    public function test_split_partial_and_full_payments_keep_channel_components_and_outstanding_balance(): void
    {
        $booking = $this->booking(10, 1000);
        $service = app(PaymentService::class);

        $service->record([
            'payer_name' => 'Split Payer',
            'payment_method_code' => 'split',
            'reference_number' => 'SPLIT-001',
            'amount' => 600,
            'payment_type' => 'partial',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 600], [
            ['payment_method_code' => 'cash', 'amount' => 250],
            ['payment_method_code' => 'gcash', 'amount' => 350, 'reference_number' => 'SPLIT-001'],
        ]);
        $service->record([
            'payer_name' => 'Card Payer',
            'payment_method_code' => 'card',
            'reference_number' => 'CARD-001',
            'amount' => 400,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 400]);

        $booking->refresh();
        $this->assertEquals(1000.0, $booking->amount_paid);
        $this->assertEquals(250.0, $booking->cash_amount);
        $this->assertEquals(350.0, $booking->gcash_amount);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertDatabaseHas('payment_components', ['payment_method_code' => 'card', 'amount' => 400]);
    }

    public function test_reschedule_and_status_changes_do_not_move_or_delete_payment_history(): void
    {
        $booking = $this->booking(20, 1000);
        $payment = app(PaymentService::class)->record([
            'received_at' => now()->subDay(),
            'payer_name' => 'Stable History',
            'payment_method_code' => 'bank_transfer',
            'reference_number' => 'BANK-001',
            'amount' => 500,
            'payment_type' => 'deposit',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 500]);
        $receivedAt = $payment->received_at->toDateTimeString();

        $booking->update([
            'check_in' => now()->addMonths(3),
            'expected_check_out' => now()->addMonths(3)->addDay(),
            'status' => 'no_show',
            'total_amount' => 1200,
        ]);
        app(PaymentService::class)->syncBooking($booking);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'verified',
            'amount' => 500,
        ]);
        $this->assertSame($receivedAt, $payment->fresh()->received_at->toDateTimeString());
        $this->assertEquals(500.0, $booking->fresh()->amount_paid);
        $this->assertEquals(700.0, $booking->fresh()->total_amount - $booking->fresh()->amount_paid);
    }

    public function test_refund_is_append_only_and_reduces_net_paid_without_deleting_original(): void
    {
        $booking = $this->booking(15, 1000);
        $service = app(PaymentService::class);
        $original = $service->record([
            'payer_name' => 'Refund Guest',
            'payment_method_code' => 'cash',
            'amount' => 1000,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 1000]);

        $refund = $service->refund($original, 300, $this->user->id, 'Approved cancellation refund', null, null, $booking->id);

        $this->assertNotSame($original->id, $refund->id);
        $this->assertSame('refund', $refund->payment_type);
        $this->assertSame($original->id, $refund->original_payment_id);
        $this->assertDatabaseHas('payments', ['id' => $original->id, 'status' => 'verified', 'amount' => 1000]);
        $this->assertEquals(700.0, $booking->fresh()->amount_paid);
    }

    public function test_unbackfilled_legacy_receipt_is_preserved_when_new_ledger_payment_syncs_booking(): void
    {
        $booking = $this->booking(5, 1000);
        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Legacy deposit',
            'amount' => 300,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'processed_by' => $this->user->id,
        ]);

        app(PaymentService::class)->record([
            'payer_name' => 'New Payer',
            'payment_method_code' => 'cash',
            'amount' => 200,
            'payment_type' => 'partial',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 200]);

        $this->assertEquals(500.0, $booking->fresh()->amount_paid);
        $this->assertEquals(500.0, $booking->fresh()->cash_amount);
    }

    public function test_room_billed_pos_sale_is_not_counted_as_a_booking_payment(): void
    {
        $booking = $this->booking(42, 1000);

        Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'pos_sale',
            'description' => 'Room-billed minibar item',
            'amount' => 25,
            'payment_method' => 'cash',
            'cash_amount' => 25,
            'processed_by' => $this->user->id,
        ]);

        app(PaymentService::class)->syncBooking($booking);

        $booking->refresh();
        $this->assertEquals(0.0, $booking->amount_paid);
        $this->assertEquals(0.0, $booking->cash_amount);
        $this->assertSame('unpaid', $booking->payment_status);
    }

    public function test_maya_bank_card_and_other_ewallet_channels_are_first_class_components(): void
    {
        foreach (['maya', 'bank_transfer', 'card', 'other_ewallet', 'other'] as $index => $method) {
            $booking = $this->booking(70 + $index, 100);
            app(PaymentService::class)->record([
                'payer_name' => 'Channel Payer',
                'payment_method_code' => $method,
                'reference_number' => strtoupper($method).'-REF-'.$index,
                'amount' => 100,
                'payment_type' => 'full',
                'status' => 'verified',
                'recorded_by' => $this->user->id,
            ], [$booking->id => 100]);

            $this->assertDatabaseHas('payment_components', [
                'payment_method_code' => $method,
                'amount' => 100,
            ]);
            $this->assertEquals(100.0, $booking->fresh()->amount_paid);
        }
    }

    public function test_cancellation_full_refund_keeps_original_and_adds_refund_receipt(): void
    {
        $booking = $this->booking(40, 500);
        $original = app(PaymentService::class)->record([
            'payer_name' => 'Cancellation Payer',
            'payment_method_code' => 'cash',
            'amount' => 500,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 500]);

        $response = $this->actingAs($this->user)->post(route('reservations.cancel', $booking), [
            'reason' => 'Guest requested cancellation',
            'payment_disposition' => 'full_refund',
        ]);

        $response->assertRedirect(route('reservations.index'));
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertEquals(0.0, $booking->fresh()->amount_paid);
        $this->assertDatabaseHas('payments', ['id' => $original->id, 'payment_type' => 'full']);
        $this->assertDatabaseHas('payments', [
            'original_payment_id' => $original->id,
            'payment_type' => 'refund',
            'amount' => 500,
            'status' => 'verified',
        ]);
    }

    public function test_backfill_command_is_dry_run_by_default(): void
    {
        $booking = $this->booking(12, 300);
        $transaction = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Legacy receipt for dry-run check',
            'amount' => 300,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'processed_by' => $this->user->id,
        ]);

        $this->artisan('payments:backfill-ledger')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertDatabaseCount('payments', 0);
        $this->assertNull($transaction->fresh()->payment_id);
    }

    public function test_split_refund_requires_its_own_reference_and_applies_after_verification(): void
    {
        $booking = $this->booking(18, 500);
        $service = app(PaymentService::class);
        $original = $service->record([
            'payer_name' => 'Split Refund Payer',
            'payment_method_code' => 'split',
            'reference_number' => 'ORIGINAL-GCASH-REF',
            'amount' => 500,
            'payment_type' => 'full',
            'status' => 'verified',
            'recorded_by' => $this->user->id,
        ], [$booking->id => 500], [
            ['payment_method_code' => 'cash', 'amount' => 200],
            ['payment_method_code' => 'gcash', 'amount' => 300, 'reference_number' => 'ORIGINAL-GCASH-REF'],
        ]);

        $refund = $service->refund($original, 200, $this->user->id, 'Split refund test', null, null, $booking->id);
        $this->assertSame('pending', $refund->status);
        $this->assertEquals(500.0, $booking->fresh()->amount_paid);

        $service->verify($refund, $this->user->id, 'REFUND-GCASH-REF');
        $this->assertEquals(300.0, $booking->fresh()->amount_paid);
        $this->assertDatabaseHas('payment_components', [
            'payment_id' => $refund->id,
            'payment_method_code' => 'gcash',
            'reference_number' => 'REFUND-GCASH-REF',
        ]);
    }

    public function test_rejected_ambiguous_backfill_is_removed_from_cached_paid_balance(): void
    {
        $booking = $this->booking(22, 500);
        $booking->update(['amount_paid' => 500, 'payment_status' => 'paid']);
        $legacy = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_in',
            'description' => 'Ambiguous old GCash receipt',
            'amount' => 500,
            'payment_method' => 'gcash',
            'gcash_amount' => 500,
            'processed_by' => $this->user->id,
        ]);
        $payment = app(PaymentService::class)->record([
            'received_at' => $legacy->created_at,
            'payer_name' => 'Ambiguous Payer',
            'payment_method_code' => 'gcash',
            'amount' => 500,
            'payment_type' => 'full',
            'status' => 'pending',
            'recorded_by' => $this->user->id,
            'legacy_transaction_id' => $legacy->id,
        ], [$booking->id => 500], [], ['skip' => true, 'skip_sync' => true]);
        $legacy->update(['payment_id' => $payment->id]);

        app(PaymentService::class)->reject($payment, $this->user->id, 'No proof found');

        $this->assertEquals(0.0, $booking->fresh()->amount_paid);
        $this->assertSame('unpaid', $booking->fresh()->payment_status);
    }

    private function booking(int $daysAhead, float $total): Booking
    {
        return Booking::create([
            'booking_ref' => 'LEDGER-'.str_pad((string) Booking::count(), 4, '0', STR_PAD_LEFT),
            'room_id' => $this->room->id,
            'booked_by_user_id' => $this->user->id,
            'guest_name' => 'Ledger Guest',
            'booker_name' => 'Ledger Booker',
            'guest_contact' => '09171111111',
            'booker_contact' => '09172222222',
            'check_in' => now()->addDays($daysAhead),
            'expected_check_out' => now()->addDays($daysAhead + 1),
            'status' => 'reserved',
            'booking_type' => 'overnight',
            'num_nights' => 1,
            'payment_status' => 'unpaid',
            'base_amount' => $total,
            'total_amount' => $total,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'checked_in_by' => $this->user->id,
        ]);
    }
}
