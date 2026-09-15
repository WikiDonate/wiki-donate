<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationPayoutTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $article;

    private $formula;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->article = Article::create([
            'title' => 'Test Article',
            'slug' => 'test-article-'.uniqid(),
            'is_active' => true,
        ]);

        $this->formula = DonationFormula::create([
            'article_id' => $this->article->id,
            'user_id' => $this->admin->id,
            'name' => 'Test Formula',
            'formula' => [
                ['organization' => 'Charity A', 'organization_id' => null, 'percentage' => 60],
                ['organization' => 'Charity B', 'organization_id' => null, 'percentage' => 40],
            ],
        ]);
    }

    private function asAdmin(): self
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function addDonation(float $amount, string $status): void
    {
        Donation::create([
            'user_id' => $this->admin->id,
            'donation_formula_id' => $this->formula->id,
            'amount' => $amount,
            'currency' => 'usd',
            'status' => $status,
            'metadata' => ['source' => 'test'],
        ]);
    }

    public function test_live_balance_computation(): void
    {
        // 1000 completed + 500 pending + 200 failed → only 1000 counts.
        $this->addDonation(1000, 'completed');
        $this->addDonation(500, 'pending');
        $this->addDonation(200, 'failed');

        $response = $this->asAdmin()->getJson('/api/v1/admin/payouts/allocations');

        $response->assertOk();

        $allocations = collect($response->json('data'));
        $a = $allocations->firstWhere('organization_name', 'Charity A');
        $b = $allocations->firstWhere('organization_name', 'Charity B');

        $this->assertSame(600.0, $a['owed']);
        $this->assertSame(0.0, $a['paid']);
        $this->assertSame(600.0, $a['balance']);
        $this->assertSame(400.0, $b['balance']);
    }

    public function test_overpay_is_rejected(): void
    {
        $this->addDonation(100, 'completed');

        // Charity A is owed 60; paying 61 must fail.
        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 61,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('organization_payouts', [
            'organization_name' => 'Charity A',
        ]);
    }

    public function test_partial_then_full_payout(): void
    {
        $this->addDonation(100, 'completed');

        // Charity A owed 60 → first payout of 40 is partial.
        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 40,
            'currency' => 'usd',
            'note' => 'bKash ref 123',
        ]);

        $response->assertCreated();
        $this->assertSame('partial', $response->json('data.type'));

        // Remaining 20 == live balance → next payout is full.
        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 20,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertSame('full', $response->json('data.type'));

        // Ledger is append-only: both rows exist.
        $this->assertDatabaseCount('organization_payouts', 2);

        // Balance is now zero and further pays are rejected.
        $response = $this->asAdmin()->getJson('/api/v1/admin/payouts/allocations');
        $a = collect($response->json('data'))->firstWhere('organization_name', 'Charity A');
        $this->assertSame(0.0, $a['balance']);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 0.01,
            'currency' => 'usd',
        ]);
        $response->assertStatus(422);
    }

    public function test_requires_admin_role(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->addDonation(100, 'completed');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/payouts/allocations')
            ->assertStatus(403);

        $this->getJson('/api/v1/admin/payouts/allocations')
            ->assertStatus(401);
    }

    public function test_history_lists_payouts(): void
    {
        $this->addDonation(100, 'completed');

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 25,
            'currency' => 'usd',
        ])->assertCreated();

        $response = $this->asAdmin()->getJson('/api/v1/admin/payouts/history'.
            '?donation_formula_id='.$this->formula->id.
            '&organization='.urlencode('Charity A'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Charity A', $response->json('data.0.organization_name'));
        $this->assertSame(25.0, $response->json('data.0.amount'));
    }
}
