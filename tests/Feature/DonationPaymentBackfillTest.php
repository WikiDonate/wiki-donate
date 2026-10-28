<?php

namespace Tests\Feature;

use App\Services\Payment\DonationPaymentBackfillService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase-1 data migration QA.
 *
 * Phase 3 dropped the legacy PayPal/Stripe id columns from `donations`, so this
 * test re-creates them for the duration of each test to assert that the
 * backfill derivation is correct and idempotent for every historical row shape.
 * (The service itself no-ops when the legacy columns are absent, which is
 * exactly what happens on a post-phase-3 database.)
 */
class DonationPaymentBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('donations', function (Blueprint $table) {
            if (! Schema::hasColumn('donations', 'paypal_order_id')) {
                $table->string('paypal_order_id')->nullable();
            }
            if (! Schema::hasColumn('donations', 'stripe_session_id')) {
                $table->string('stripe_session_id')->nullable();
            }
            if (! Schema::hasColumn('donations', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id')->nullable();
            }
        });
    }

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

    public function test_backfill_no_ops_once_the_legacy_columns_are_dropped(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn(['paypal_order_id', 'stripe_session_id', 'stripe_payment_intent_id']);
        });

        // Must not throw even though the referenced columns are gone.
        app(DonationPaymentBackfillService::class)->run();

        $this->assertTrue(true);
    }
}
