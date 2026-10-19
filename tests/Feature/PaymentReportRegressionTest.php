<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase-2 regression: reports and summaries must derive every payment-method
 * value from the generic `payment_method` + `gateway_reference` pair.
 *
 * Pre-refactor these endpoints inferred the method from the (now dropped)
 * `paypal_order_id` / `stripe_session_id` columns. This suite pins the
 * post-refactor contract so a future edit that reintroduces legacy reads fails
 * loudly, and it also proves rows written before the backfill (payment_method
 * NULL) still classify the way the old code did (Stripe).
 */
class PaymentReportRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function formula(): DonationFormula
    {
        $user = User::factory()->create();
        $article = Article::create([
            'slug' => 'payments-report-'.uniqid(),
            'title' => 'Payments Report Article',
        ]);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'name' => 'Report Formula '.uniqid(),
            'formula' => [
                ['organization' => 'Wiki Org', 'percentage' => 100],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function donation(array $attrs): Donation
    {
        return Donation::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'amount' => 10,
            'currency' => 'usd',
            'status' => 'completed',
        ], $attrs));
    }

    private function userToken(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // -------------------------------------------------------------------------
    // Donor report (/api/v1/report/donations)
    // -------------------------------------------------------------------------

    public function test_donor_report_classifies_methods_from_the_generic_pair(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula();

        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL-REPORT-1',
            'donor_email' => 'donor@example.com',
        ]);
        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_report_1',
            'donor_email' => 'donor@example.com',
        ]);

        $res = $this->withHeaders($this->userToken($donor))->getJson('/api/v1/report/donations');

        $res->assertOk()->assertJsonPath('success', true);

        $byMethod = collect($res->json('data.summary.byPaymentMethod'))->keyBy('payment_method');
        $this->assertEquals(1, (int) $byMethod['paypal']['count']);
        $this->assertEquals(1, (int) $byMethod['stripe']['count']);

        $methods = collect($res->json('data.donations'))->pluck('payment_method')->sort()->values()->all();
        $this->assertSame(['paypal', 'stripe'], $methods);
    }

    public function test_donor_report_payment_id_prefers_metadata_then_falls_back_to_the_reference(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula();

        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL-ORDER-9',
            'metadata' => ['payment_id' => 'CAPTURE-9'],
        ]);
        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_report_2',
        ]);

        $rows = collect($this->withHeaders($this->userToken($donor))
            ->getJson('/api/v1/report/donations')
            ->json('data.donations'))->keyBy('payment_method');

        $this->assertSame('CAPTURE-9', $rows['paypal']['payment_id']);
        $this->assertSame('cs_report_2', $rows['stripe']['payment_id'], 'falls back to gateway_reference');
    }

    public function test_donor_report_search_matches_the_generic_pair(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula();

        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL-SEARCH-1',
        ]);
        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_search_1',
        ]);

        $byReference = $this->withHeaders($this->userToken($donor))
            ->getJson('/api/v1/report/donations?search=PAYPAL-SEARCH-1')
            ->json('data.donations');
        $this->assertCount(1, $byReference);
        $this->assertSame('paypal', $byReference[0]['payment_method']);

        $byMethod = $this->withHeaders($this->userToken($donor))
            ->getJson('/api/v1/report/donations?search=stripe')
            ->json('data.donations');
        $this->assertCount(1, $byMethod);
        $this->assertSame('stripe', $byMethod[0]['payment_method']);
    }

    public function test_legacy_rows_without_a_payment_method_still_default_to_stripe(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula();

        // Simulates a row that pre-dates the backfill: no generic pair at all.
        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'payment_method' => null,
            'gateway_reference' => null,
        ]);

        $res = $this->withHeaders($this->userToken($donor))->getJson('/api/v1/report/donations');

        $res->assertOk();
        $this->assertSame('stripe', $res->json('data.donations.0.payment_method'));
        $this->assertSame(1, (int) $res->json('data.summary.byPaymentMethod.0.count'));
        $this->assertSame('stripe', $res->json('data.summary.byPaymentMethod.0.payment_method'));
    }

    // -------------------------------------------------------------------------
    // Admin transactions (/api/v1/admin/transactions)
    // -------------------------------------------------------------------------

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    public function test_admin_transactions_report_the_generic_method_per_row(): void
    {
        $admin = $this->admin();
        $formula = $this->formula();

        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAY-TX-1',
            'donor_name' => 'Pay Pal',
        ]);
        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_tx_1',
            'donor_name' => 'Stripe Sue',
        ]);

        $rows = collect($this->withHeaders($this->userToken($admin))
            ->getJson('/api/v1/admin/transactions')
            ->json('data'))->keyBy('donor_name');

        $this->assertSame('paypal', $rows['Pay Pal']['method']);
        $this->assertSame('stripe', $rows['Stripe Sue']['method']);
    }

    public function test_admin_transactions_method_filter_uses_the_generic_column(): void
    {
        $admin = $this->admin();
        $formula = $this->formula();

        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAY-TX-2',
            'amount' => 100,
        ]);
        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_tx_2',
            'amount' => 25,
        ]);

        $paypal = $this->withHeaders($this->userToken($admin))
            ->getJson('/api/v1/admin/transactions?method=paypal')
            ->json('data');

        $this->assertCount(1, $paypal);
        $this->assertSame('paypal', $paypal[0]['method']);
        $this->assertEquals(100.0, $paypal[0]['amount']);
    }

    // -------------------------------------------------------------------------
    // Admin dashboard donations (/api/v1/admin/donations)
    // -------------------------------------------------------------------------

    public function test_admin_donations_expose_and_search_the_generic_pair(): void
    {
        $admin = $this->admin();
        $formula = $this->formula();

        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL-DASH-1',
            'donor_email' => 'dash@example.com',
        ]);

        $list = $this->withHeaders($this->userToken($admin))
            ->getJson('/api/v1/admin/donations')
            ->json('data');

        $this->assertSame('paypal', $list[0]['payment_method']);
        $this->assertSame('PAYPAL-DASH-1', $list[0]['gateway_reference']);
        $this->assertSame('PAYPAL-DASH-1', $list[0]['payment_id'], 'falls back to gateway_reference');

        $hit = $this->withHeaders($this->userToken($admin))
            ->getJson('/api/v1/admin/donations?search=PAYPAL-DASH-1')
            ->json('data');
        $this->assertCount(1, $hit);

        // The method filter (driven by GET /v1/payment-methods in the UI)
        // matches on the generic column, not a legacy id inference.
        $this->donation([
            'donation_formula_id' => $formula->id,
            'payment_method' => 'stripe',
            'gateway_reference' => 'cs_dash_1',
            'donor_email' => 'dash@example.com',
        ]);

        $filtered = $this->withHeaders($this->userToken($admin))
            ->getJson('/api/v1/admin/donations?method=stripe')
            ->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('stripe', $filtered[0]['payment_method']);
    }
}
