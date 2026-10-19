<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Services\Payment\DonationPaymentBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase-1 data migration QA.
 *
 * The generic `payment_method` + `gateway_reference` pair must be derivable
 * from the legacy PayPal/Stripe id columns for every historical row shape, and
 * the derivation must be idempotent (safe to re-run, never clobbers a correct
 * row, never leaves a legacy-only row unclassified).
 */
class DonationPaymentBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function insertLegacyRow(array $attrs): int
    {
        return DB::table('donations')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'amount' => 10,
            'currency' => 'usd',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function row(int $id): object
    {
        return DB::table('donations')->where('id', $id)->first();
    }

    public function test_paypal_rows_backfill_to_paypal_and_keep_the_order_id(): void
    {
        $id = $this->insertLegacyRow(['paypal_order_id' => 'PAYPAL-ORDER-1']);

        app(DonationPaymentBackfillService::class)->run();

        $row = $this->row($id);
        $this->assertSame('paypal', $row->payment_method);
        $this->assertSame('PAYPAL-ORDER-1', $row->gateway_reference);
    }

    public function test_stripe_rows_prefer_session_id_over_intent_id(): void
    {
        $id = $this->insertLegacyRow([
            'stripe_session_id' => 'cs_session_1',
            'stripe_payment_intent_id' => 'pi_intent_1',
        ]);

        app(DonationPaymentBackfillService::class)->run();

        $row = $this->row($id);
        $this->assertSame('stripe', $row->payment_method);
        $this->assertSame('cs_session_1', $row->gateway_reference, 'session id wins over intent id');
    }

    public function test_stripe_intent_only_rows_still_backfill(): void
    {
        $id = $this->insertLegacyRow(['stripe_payment_intent_id' => 'pi_intent_only']);

        app(DonationPaymentBackfillService::class)->run();

        $row = $this->row($id);
        $this->assertSame('stripe', $row->payment_method);
        $this->assertSame('pi_intent_only', $row->gateway_reference);
    }

    public function test_rows_with_no_gateway_ids_stay_unclassified(): void
    {
        $id = $this->insertLegacyRow([]);

        app(DonationPaymentBackfillService::class)->run();

        $row = $this->row($id);
        $this->assertNull($row->payment_method);
        $this->assertNull($row->gateway_reference);
    }

    public function test_backfill_is_idempotent_and_never_reclassifies_a_correct_row(): void
    {
        $id = $this->insertLegacyRow(['paypal_order_id' => 'PAYPAL-ORDER-2']);
        $service = app(DonationPaymentBackfillService::class);

        $service->run();
        $service->run();
        $service->run();

        $row = $this->row($id);
        $this->assertSame('paypal', $row->payment_method);
        $this->assertSame('PAYPAL-ORDER-2', $row->gateway_reference);
        $this->assertSame('PAYPAL-ORDER-2', $row->paypal_order_id, 'legacy column untouched in phase 1');
    }

    public function test_stale_generic_value_is_repaired_from_the_legacy_column(): void
    {
        // Simulates a row written by old code after the column was added: the
        // generic pair is wrong/empty while the legacy id is authoritative.
        $id = $this->insertLegacyRow([
            'paypal_order_id' => 'PAYPAL-ORDER-3',
            'payment_method' => 'stripe',
            'gateway_reference' => null,
        ]);

        app(DonationPaymentBackfillService::class)->run();

        $row = $this->row($id);
        $this->assertSame('paypal', $row->payment_method);
        $this->assertSame('PAYPAL-ORDER-3', $row->gateway_reference);
    }

    public function test_saving_mirror_keeps_generic_and_legacy_columns_in_sync(): void
    {
        $paypal = Donation::create([
            'paypal_order_id' => 'PAYPAL-MIRROR-1',
            'amount' => 25,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        $this->assertSame('paypal', $paypal->payment_method);
        $this->assertSame('PAYPAL-MIRROR-1', $paypal->gateway_reference);

        $stripe = Donation::create([
            'stripe_session_id' => 'cs_mirror_1',
            'stripe_payment_intent_id' => 'pi_mirror_1',
            'amount' => 25,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        $this->assertSame('stripe', $stripe->payment_method);
        $this->assertSame('cs_mirror_1', $stripe->gateway_reference);
    }
}
