<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase-2/3 verification of the pluggable payment-method refactor.
 *
 * The refactor's contract is that the generic `payment_method` +
 * `gateway_reference` pair is the ONLY payment identity anywhere in the
 * application. This suite pins that contract in two ways:
 *
 *  1. Schema: phase 3 removed every legacy identity column/table, so a fresh
 *     database must not carry `donations.paypal_order_id` /
 *     `stripe_session_id` / `stripe_payment_intent_id`, the `paypal_pending_orders`
 *     table, or `organization_payouts.method` — and it must enforce the generic
 *     uniqueness pair.
 *  2. Behaviour: the report + admin transaction surfaces group/label rows from
 *     the generic column only. These endpoints read the columns directly, so on
 *     a post-phase-3 schema a lingering legacy read would fail loudly instead of
 *     silently mislabelling rows (the pre-refactor `paypal_order_id ? paypal :
 *     stripe` inference).
 *
 * A static grep sweep backs this up and is documented in the task record:
 * `grep -rn "paypal_order_id\|stripe_session_id\|stripe_payment_intent_id"
 *  app routes resources/js` returns no live reference — only
 * `DonationPaymentBackfillService`, which intentionally probes for the columns
 * with `Schema::hasColumn()` and no-ops on a post-phase-3 database.
 */
class PaymentLegacyCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Editor']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer '.$this->admin->createToken('test')->plainTextToken];
    }

    private function articleWithFormula(): DonationFormula
    {
        $article = Article::create([
            'slug' => 'legacy-cleanup-'.uniqid(),
            'title' => 'Legacy Cleanup Article',
        ]);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Cleanup Formula '.uniqid(),
            'formula' => [
                ['organization' => 'Wiki Org', 'percentage' => 60],
                ['organization' => 'Other Org', 'percentage' => 40],
            ],
        ]);
    }

    private function donation(DonationFormula $formula, string $method, string $reference, float $amount): Donation
    {
        return Donation::create([
            'donation_formula_id' => $formula->id,
            'payment_method' => $method,
            'gateway_reference' => $reference,
            'donor_name' => 'Test Donor',
            'donor_email' => 'donor@example.com',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'completed',
            // Extra gateway ids live in metadata from phase 3 on.
            'metadata' => ['payment_id' => $reference, 'source' => $method.'_webhook'],
        ]);
    }

    public function test_legacy_donation_identity_columns_are_gone(): void
    {
        foreach (['paypal_order_id', 'stripe_session_id', 'stripe_payment_intent_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('donations', $column),
                "donations.{$column} should have been dropped in phase 3"
            );
        }

        $this->assertTrue(Schema::hasColumn('donations', 'payment_method'));
        $this->assertTrue(Schema::hasColumn('donations', 'gateway_reference'));
    }

    public function test_pending_orders_table_uses_generic_identity(): void
    {
        $this->assertFalse(Schema::hasTable('paypal_pending_orders'));
        $this->assertTrue(Schema::hasTable('payment_pending_orders'));
        $this->assertTrue(Schema::hasColumn('payment_pending_orders', 'payment_method'));
        $this->assertTrue(Schema::hasColumn('payment_pending_orders', 'gateway_reference'));
    }

    public function test_payout_column_uses_generic_name(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_payouts', 'payment_method'));
        $this->assertFalse(Schema::hasColumn('organization_payouts', 'method'));
    }

    public function test_generic_identity_pair_is_unique(): void
    {
        $this->assertTrue(
            Schema::hasIndex('donations', 'donations_method_reference_unique'),
            'donations must enforce unique (payment_method, gateway_reference)'
        );
    }

    /**
     * Regression: reports/summary numbers are derived from the generic column
     * and match a hand-computed split per method.
     */
    public function test_report_and_summary_group_totals_by_generic_method(): void
    {
        $formula = $this->articleWithFormula();

        $this->donation($formula, 'paypal', 'PAYPAL-ORDER-1', 30);
        $this->donation($formula, 'paypal', 'PAYPAL-ORDER-2', 20);
        $this->donation($formula, 'stripe', 'cs_test_123', 50);

        // Donor report summary
        $report = $this->withHeaders($this->authHeader())->getJson('/api/v1/report/donations');
        $report->assertOk()->assertJsonPath('success', true);

        $summary = collect($report->json('data.summary'));
        $this->assertSame(100.0, (float) $summary['totalDonated']);
        $this->assertSame(3, (int) $summary['totalDonations']);

        $byMethod = collect($report->json('data.summary.byPaymentMethod'))
            ->keyBy('payment_method');
        $this->assertSame(2, (int) $byMethod['paypal']['count']);
        $this->assertSame(50.0, (float) $byMethod['paypal']['total']);
        $this->assertSame(1, (int) $byMethod['stripe']['count']);
        $this->assertSame(50.0, (float) $byMethod['stripe']['total']);

        // Rows expose the generic method code, never a legacy-column inference.
        $rows = collect($report->json('data.donations'))->keyBy('payment_id');
        $this->assertSame('paypal', $rows['PAYPAL-ORDER-1']['payment_method']);
        $this->assertSame('stripe', $rows['cs_test_123']['payment_method']);

        // Admin transactions: same totals, method column is the generic code.
        $tx = $this->getJson('/api/v1/admin/transactions', $this->authHeader());
        $tx->assertOk();
        $income = collect($tx->json('data'))->where('type', 'income');
        $this->assertSame(100.0, (float) $income->sum('amount'));
        $this->assertEqualsCanonicalizing(
            ['paypal', 'stripe'],
            $income->pluck('method')->unique()->values()->all()
        );

        // Admin summary totals are unchanged by the rename.
        $sum = $this->getJson('/api/v1/admin/transactions/summary', $this->authHeader());
        $sum->assertOk();
        $this->assertSame(100.0, (float) $sum->json('data.totalIncome'));

        // Method filter still works against the generic column.
        $filtered = $this->getJson('/api/v1/admin/transactions?method=paypal', $this->authHeader());
        $filtered->assertOk();
        $methods = collect($filtered->json('data'))->where('type', 'income')->pluck('method')->unique();
        $this->assertSame(['paypal'], $methods->values()->all());
    }

    public function test_payout_rows_carry_generic_payment_method(): void
    {
        $formula = $this->articleWithFormula();

        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 15,
            'currency' => 'USD',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
            'payment_method' => 'paypal',
        ]);

        $rows = $this->getJson('/api/v1/admin/transactions?type=expense', $this->authHeader())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('paypal', $rows[0]['method']);
    }

    /**
     * The generic gateway scope is the only lookup path left for webhooks.
     */
    public function test_gateway_scope_matches_generic_pair(): void
    {
        $formula = $this->articleWithFormula();
        $donation = $this->donation($formula, 'paypal', 'PAYPAL-ORDER-9', 10);

        $this->assertSame(
            $donation->id,
            Donation::gateway('paypal', 'PAYPAL-ORDER-9')->value('id')
        );

        // A Stripe session id must never match a PayPal lookup.
        $this->assertNull(Donation::gateway('paypal', 'cs_test_999')->first());
    }
}
