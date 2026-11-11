<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use App\Services\Payout\OrganizationPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pins the distribution-status calculation shown on both donation lists:
 * payout coverage of a formula's owed allocations, using the same rules as
 * the payout balance (completed USD donations, paid + pending payouts,
 * reversed excluded).
 */
class DonationDistributionStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{organization: string, percentage: int}>|null  $items
     */
    private function formula(?array $items = null): DonationFormula
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'distribution-'.uniqid(),
            'title' => 'Distribution Article',
        ]);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'Distribution Formula '.uniqid(),
            'formula' => $items ?? [
                ['organization' => 'Org A', 'percentage' => 50],
                ['organization' => 'Org B', 'percentage' => 50],
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
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payout(DonationFormula $formula, string $org, float $amount, string $status, array $overrides = []): OrganizationPayout
    {
        return OrganizationPayout::create(array_merge([
            'donation_formula_id' => $formula->id,
            'organization_name' => $org,
            'organization_key' => OrganizationPayout::makeKey($org),
            'actor_id' => User::factory()->create()->id,
            'amount' => $amount,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ], $overrides));
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function reportStatus(User $donor, Donation $donation): ?string
    {
        $rows = collect($this->withHeaders($this->token($donor))
            ->getJson('/api/v1/report/donations')
            ->json('data.donations'))->keyBy('id');

        return $rows[$donation->id]['distribution_status'];
    }

    private function adminStatus(User $admin, Donation $donation): ?string
    {
        $rows = collect($this->withHeaders($this->token($admin))
            ->getJson('/api/v1/admin/donations')
            ->json('data'))->keyBy('id');

        return $rows[$donation->id]['distribution_status'];
    }

    public function test_status_moves_from_partial_to_distributed_and_both_endpoints_agree(): void
    {
        $admin = $this->admin();
        $donor = User::factory()->create();
        $formula = $this->formula();
        $donation = $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 100,
        ]);

        // Half of the owed 100 has been paid out.
        $this->payout($formula, 'Org A', 50, 'paid');

        $this->assertSame('partial', $this->reportStatus($donor, $donation));
        $this->assertSame('partial', $this->adminStatus($admin, $donation));

        // A pending transfer still reserves the balance, so coverage is full.
        $this->payout($formula, 'Org B', 50, 'pending');

        $this->assertSame('distributed', $this->reportStatus($donor, $donation));
        $this->assertSame('distributed', $this->adminStatus($admin, $donation));
    }

    public function test_failed_and_reversed_payouts_do_not_cover_the_balance(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula([['organization' => 'Org A', 'percentage' => 100]]);
        $donation = $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 100,
        ]);

        $this->payout($formula, 'Org A', 100, 'failed');
        $this->payout($formula, 'Org A', 100, 'reversed', ['reversed_at' => now()]);

        $this->assertSame('pending', $this->reportStatus($donor, $donation));
    }

    public function test_overpayment_is_distributed_not_negative(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula([['organization' => 'Org A', 'percentage' => 100]]);
        $donation = $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 100,
        ]);

        $this->payout($formula, 'Org A', 150, 'paid');

        $this->assertSame('distributed', $this->reportStatus($donor, $donation));
    }

    public function test_non_payable_donations_have_no_distribution_status(): void
    {
        $donor = User::factory()->create();
        $formula = $this->formula([['organization' => 'Org A', 'percentage' => 100]]);

        // Legacy currency: excluded from USD payout math entirely.
        $legacy = $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 500,
            'currency' => 'eur',
        ]);

        // Not completed: money never landed.
        $unfinished = $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 25,
            'status' => 'pending',
        ]);

        $this->assertNull($this->reportStatus($donor, $legacy));
        $this->assertNull($this->reportStatus($donor, $unfinished));
    }

    public function test_distribution_math_matches_the_admin_transaction_summary(): void
    {
        $admin = $this->admin();
        $donor = User::factory()->create();
        $formula = $this->formula([['organization' => 'Org A', 'percentage' => 100]]);
        $this->donation([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'amount' => 100,
        ]);
        $this->payout($formula, 'Org A', 40, 'paid');

        $service = app(OrganizationPayoutService::class);
        $this->assertSame(100.0, $service->totalOwed());
        $this->assertSame([$formula->id => 'partial'], $service->distributionStatuses([$formula->id]));

        $summary = $this->withHeaders($this->token($admin))
            ->getJson('/api/v1/admin/transactions/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(40.0, $summary['totalPayouts']);
        $this->assertEquals(60.0, $summary['remainingPayable']);
    }
}
