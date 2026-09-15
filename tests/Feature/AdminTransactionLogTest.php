<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the Admin Transaction Log:
 * - summary math (income / payouts / remaining payable / net in hand)
 * - merged feed ordering + typing (income | expense)
 * - filter behavior (type, method, date range, org)
 */
class AdminTransactionLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Admin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Editor']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer '.$this->admin->createToken('test')->plainTextToken];
    }

    private function articleWithFormula(float $percentage): DonationFormula
    {
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Formula '.uniqid(),
            'formula' => [
                ['organization' => 'Wiki Org', 'percentage' => $percentage],
                ['organization' => 'Other Org', 'percentage' => 100 - $percentage],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // AUTH — endpoints are admin-only
    // -------------------------------------------------------------------------

    public function test_transactions_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/transactions')->assertStatus(401);
        $this->getJson('/api/v1/admin/transactions/summary')->assertStatus(401);
        $this->get('/api/v1/admin/transactions/export')->assertStatus(401);
    }

    public function test_transactions_forbid_non_admin_role(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        $header = ['Authorization' => 'Bearer '.$editor->createToken('test')->plainTextToken];

        $this->getJson('/api/v1/admin/transactions', $header)->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Summary math
    // -------------------------------------------------------------------------

    public function test_summary_computes_income_payouts_remaining_and_net(): void
    {
        $formula = $this->articleWithFormula(60);

        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Bob',
            'donor_email' => 'bob@example.com',
            'amount' => 50,
            'currency' => 'usd',
            'status' => 'pending', // ignored in income math
        ]);

        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 30,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
            'actor' => $this->admin->id,
            'method' => 'bank',
        ]);

        $res = $this->getJson('/api/v1/admin/transactions/summary', $this->authHeader());

        $res->assertOk()
            ->assertJsonPath('data.totalIncome', 100.0)
            ->assertJsonPath('data.totalPayouts', 30.0)
            ->assertJsonPath('data.netInHand', 70.0);

        // remainingPayable uses live owed calc: owed to Wiki Org = 100 * 60% = 60, paid 30 => 30
        $this->assertEquals(30.0, $res->json('data.remainingPayable'));
    }

    public function test_summary_is_zero_when_no_data(): void
    {
        $res = $this->getJson('/api/v1/admin/transactions/summary', $this->authHeader());

        $res->assertOk()->assertJson([
            'data' => [
                'totalIncome' => 0.0,
                'totalPayouts' => 0.0,
                'remainingPayable' => 0.0,
                'netInHand' => 0.0,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Merged feed: ordering + typing
    // -------------------------------------------------------------------------

    public function test_feed_merges_income_and_expense_sorted_by_date_desc(): void
    {
        $formula = $this->articleWithFormula(50);

        $older = Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
            'created_at' => now()->subDays(2),
        ]);
        $newer = Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Bob',
            'donor_email' => 'bob@example.com',
            'amount' => 40,
            'currency' => 'usd',
            'status' => 'completed',
            'created_at' => now()->subDay(),
        ]);

        $payout = OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 20,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/transactions', $this->authHeader());

        $res->assertOk();
        $rows = $res->json('data');

        $this->assertCount(3, $rows);
        $this->assertEquals('payout-'.$payout->id, $rows[0]['id']); // most recent first
        $this->assertEquals('expense', $rows[0]['type']);
        $this->assertEquals('donation-'.$newer->id, $rows[1]['id']);
        $this->assertEquals('income', $rows[1]['type']);
        $this->assertEquals('donation-'.$older->id, $rows[2]['id']);
    }

    public function test_rows_include_expected_fields(): void
    {
        $formula = $this->articleWithFormula(50);
        $donation = Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 20,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
            'actor' => $this->admin->id,
        ]);

        $rows = $this->getJson('/api/v1/admin/transactions', $this->authHeader())->json('data');
        $income = collect($rows)->firstWhere('type', 'income');
        $expense = collect($rows)->firstWhere('type', 'expense');

        $this->assertEquals('donation-'.$donation->id, $income['id']);
        $this->assertEquals('Alice', $income['donor_name']);
        $this->assertEquals('stripe', $income['method']);
        $this->assertEquals(100.0, $income['amount']);
        $this->assertNotNull($income['article']);

        $this->assertEquals('Wiki Org', $expense['organization_name']);
        $this->assertEquals('partial', $expense['payout_type']);
        $this->assertEquals(20.0, $expense['amount']);
        $this->assertEquals($this->admin->username ?? null, $expense['actor']);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_type_filter_returns_only_requested_rows(): void
    {
        $formula = $this->articleWithFormula(50);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 20,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $incomeOnly = $this->getJson('/api/v1/admin/transactions?type=income', $this->authHeader())->json('data');
        $expenseOnly = $this->getJson('/api/v1/admin/transactions?type=expense', $this->authHeader())->json('data');

        $this->assertCount(1, $incomeOnly);
        $this->assertEquals('income', $incomeOnly[0]['type']);
        $this->assertCount(1, $expenseOnly);
        $this->assertEquals('expense', $expenseOnly[0]['type']);
    }

    public function test_method_filter_matches_income_source(): void
    {
        $formula = $this->articleWithFormula(50);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
            'paypal_order_id' => 'PAY-123',
        ]);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Bob',
            'donor_email' => 'bob@example.com',
            'amount' => 25,
            'currency' => 'usd',
            'status' => 'completed',
            'stripe_session_id' => 'cs_test_123',
        ]);

        $paypal = $this->getJson('/api/v1/admin/transactions?method=paypal', $this->authHeader())->json('data');
        $this->assertCount(1, $paypal);
        $this->assertEquals('paypal', $paypal[0]['method']);
        $this->assertEquals(100.0, $paypal[0]['amount']);
    }

    public function test_date_range_filter_applies_to_both_types(): void
    {
        $formula = $this->articleWithFormula(50);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Old',
            'donor_email' => 'old@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
            'created_at' => now()->subDays(10),
        ]);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'New',
            'donor_email' => 'new@example.com',
            'amount' => 40,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 10,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now()->subDays(9),
        ]);

        $today = now()->toDateString();
        $rows = $this->getJson("/api/v1/admin/transactions?from={$today}", $this->authHeader())->json('data');

        $this->assertCount(1, $rows);
        $this->assertEquals('income', $rows[0]['type']);
        $this->assertEquals('New', $rows[0]['donor_name']);

        $summary = $this->getJson("/api/v1/admin/transactions/summary?from={$today}", $this->authHeader())->json('data');
        $this->assertEquals(40.0, $summary['totalIncome']);
        $this->assertEquals(0.0, $summary['totalPayouts']);
    }

    public function test_org_filter_limits_expense_rows(): void
    {
        $formula = $this->articleWithFormula(50);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 10,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Other Org',
            'amount' => 5,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $rows = $this->getJson('/api/v1/admin/transactions?type=expense&org=Wiki Org', $this->authHeader())->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('Wiki Org', $rows[0]['organization_name']);
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    public function test_csv_export_contains_income_and_expense_rows(): void
    {
        $formula = $this->articleWithFormula(50);
        Donation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Wiki Org',
            'amount' => 20,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $res = $this->get('/api/v1/admin/transactions/export', $this->authHeader());

        $res->assertOk();
        $csv = $this->extractStreamedContent($res);

        $this->assertStringContainsString('income,Donation from Alice', $csv);
        $this->assertStringContainsString('expense,Payout to Wiki Org', $csv);
        $this->assertStringContainsString('-20.00', $csv);
    }

    /**
     * Captures the streamed CSV body by invoking the response's sendContent.
     */
    private function extractStreamedContent($response): string
    {
        $ref = new \ReflectionMethod($response, 'sendContent');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($response);

        return (string) ob_get_clean();
    }
}
