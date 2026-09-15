<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin payouts: append-only ledger with live computed balances (Option A —
 * owed recomputed from CURRENT formula JSON, no donation-time snapshots).
 *
 * Matrix:
 * - allocations: owed = Σ(completed donations × percentage/100) − Σ(payouts)
 * - only status=completed donations count toward owed
 * - full payout → type=full, zero balance; partial → type=partial
 * - partial then full → two append-only ledger rows
 * - overpay rejected; amount <= 0 rejected
 * - unauthenticated + non-admin rejected
 * - race guard: second full payout against depleted balance rejected
 */
class OrganizationPayoutTest extends TestCase
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

    private function formulaWithDonation(string $org = 'Water.org', float $total = 1000.0, string $status = 'completed'): DonationFormula
    {
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Formula '.uniqid(),
            'formula' => [['organization' => $org, 'percentage' => 100]],
        ]);

        Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Alice',
            'donor_email' => 'alice@example.com',
            'amount' => $total,
            'currency' => 'usd',
            'status' => $status,
        ]);

        return $formula;
    }

    // ---------------------------------------------------------------------
    // AUTH
    // ---------------------------------------------------------------------

    public function test_payouts_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/payouts')->assertStatus(401);
        $this->getJson('/api/v1/admin/payouts/allocations')->assertStatus(401);
        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => 1,
            'organization_name' => 'X',
            'amount' => 10,
        ])->assertStatus(401);
    }

    public function test_payouts_forbid_non_admin_role(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        $header = ['Authorization' => 'Bearer '.$editor->createToken('test')->plainTextToken];

        $this->getJson('/api/v1/admin/payouts/allocations', $header)->assertStatus(403);
        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => 1,
            'organization_name' => 'X',
            'amount' => 10,
        ], $header)->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // LIVE BALANCE COMPUTATION
    // ---------------------------------------------------------------------

    public function test_allocations_compute_owed_from_current_formula_json(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 1000.00);

        // Second completed donation on the same formula adds up (Option A).
        Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Bob',
            'donor_email' => 'bob@example.com',
            'amount' => 500.00,
            'currency' => 'usd',
            'status' => 'completed',
        ]);

        // Pending donation must NOT count toward owed.
        Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Carl',
            'donor_email' => 'carl@example.com',
            'amount' => 999.00,
            'currency' => 'usd',
            'status' => 'pending',
        ]);

        $res = $this->getJson('/api/v1/admin/payouts/allocations', $this->authHeader());
        $res->assertStatus(200);

        $alloc = collect($res->json('allocations'))
            ->first(fn ($a) => $a['donation_formula_id'] === $formula->id);

        $this->assertNotNull($alloc);
        $this->assertSame('Water.org', $alloc['organization_name']);
        $this->assertEquals(1500.00, $alloc['owed']);
        $this->assertEquals(0.00, $alloc['paid']);
        $this->assertEquals(1500.00, $alloc['balance']);
    }

    // ---------------------------------------------------------------------
    // FULL / PARTIAL PAYOUTS
    // ---------------------------------------------------------------------

    public function test_full_payout_marks_type_full_and_zeroes_balance(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 1000.00);

        $res = $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 1000.00,
            'currency' => 'usd',
        ], $this->authHeader());

        $res->assertStatus(201)
            ->assertJsonPath('payout.type', 'full');

        $alloc = collect($this->getJson('/api/v1/admin/payouts/allocations', $this->authHeader())
            ->json('allocations'))
            ->first(fn ($a) => $a['donation_formula_id'] === $formula->id);

        $this->assertEquals(0.0, round((float) $alloc['balance'], 2));
        $this->assertEquals(1000.00, $alloc['paid']);
    }

    public function test_partial_then_full_creates_two_append_only_entries(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 1000.00);

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 400.00,
            'currency' => 'usd',
        ], $this->authHeader())->assertStatus(201)
            ->assertJsonPath('payout.type', 'partial');

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 600.00,
            'currency' => 'usd',
        ], $this->authHeader())->assertStatus(201)
            ->assertJsonPath('payout.type', 'full');

        $this->assertSame(2, OrganizationPayout::count());
        $this->assertEquals(1000.00, OrganizationPayout::sum('amount'));
    }

    // ---------------------------------------------------------------------
    // VALIDATION
    // ---------------------------------------------------------------------

    public function test_overpay_is_rejected(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 100.00);

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 100.01,
            'currency' => 'usd',
        ], $this->authHeader())->assertStatus(422);

        $this->assertSame(0, OrganizationPayout::count());
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 100.00);

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 0,
        ], $this->authHeader())->assertStatus(422);

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => -5,
        ], $this->authHeader())->assertStatus(422);

        $this->assertSame(0, OrganizationPayout::count());
    }

    // ---------------------------------------------------------------------
    // RACE GUARD
    // ---------------------------------------------------------------------

    public function test_second_full_payout_after_depletion_is_rejected(): void
    {
        $formula = $this->formulaWithDonation('Water.org', 1000.00);

        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 1000.00,
            'currency' => 'usd',
        ], $this->authHeader())->assertStatus(201);

        // Second attempt must see the freshly written balance (0), not a stale 1000.
        $this->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Water.org',
            'amount' => 1000.00,
            'currency' => 'usd',
        ], $this->authHeader())->assertStatus(422);

        $this->assertSame(1000.00, round((float) OrganizationPayout::sum('amount'), 2));
    }
}
