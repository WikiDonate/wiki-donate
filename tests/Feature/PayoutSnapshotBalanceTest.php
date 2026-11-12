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
use Tests\TestCase;

/**
 * Owed balances must come from each donation's frozen formula snapshot, so
 * editing a formula's percentages later cannot re-divide money that was
 * already collected or paid out.
 */
class PayoutSnapshotBalanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{organization: string, percentage: int}>  $items
     */
    private function formula(array $items): DonationFormula
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'snapshot-balance-'.uniqid(),
            'title' => 'Snapshot Balance Article',
        ]);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'Snapshot Formula '.uniqid(),
            'formula' => $items,
        ]);
    }

    /**
     * @param  list<array{organization: string, percentage: int}>|null  $snapshot
     */
    private function donation(DonationFormula $formula, float $amount, ?array $snapshot = null): Donation
    {
        return Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $formula->id,
            'amount' => $amount,
            'currency' => 'usd',
            'status' => 'completed',
            'metadata' => $snapshot === null ? null : ['formula_snapshot' => $snapshot],
        ]);
    }

    private function payout(DonationFormula $formula, string $org, float $amount, string $status = 'paid'): OrganizationPayout
    {
        return OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_name' => $org,
            'organization_key' => OrganizationPayout::makeKey($org),
            'actor_id' => User::factory()->create()->id,
            'amount' => $amount,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    private function allocations(): array
    {
        return app(OrganizationPayoutService::class)
            ->payableAllocations()
            ->keyBy('organization_key')
            ->all();
    }

    public function test_editing_percentages_does_not_reallocate_past_donations(): void
    {
        $split = [
            ['organization' => 'Org A', 'percentage' => 50],
            ['organization' => 'Org B', 'percentage' => 50],
        ];
        $formula = $this->formula($split);

        // $30 collected at 50/50, then $10 paid to each org.
        foreach ([1, 2, 3] as $i) {
            $this->donation($formula, 10, $split);
        }
        $this->payout($formula, 'Org A', 10);
        $this->payout($formula, 'Org B', 10);

        // The admin later changes the split to 10/90 …
        $edited = [
            ['organization' => 'Org A', 'percentage' => 10],
            ['organization' => 'Org B', 'percentage' => 90],
        ];
        $formula->forceFill(['formula' => $edited])->save();

        // … and a new donation is made under the new split.
        $this->donation($formula, 10, $edited);

        $rows = $this->allocations();
        $a = $rows[OrganizationPayout::makeKey('Org A')];
        $b = $rows[OrganizationPayout::makeKey('Org B')];

        // Past donations keep their 50/50 share: 15 + 1 vs 15 + 9.
        $this->assertEquals(16.0, $a['owed']);
        $this->assertEquals(10.0, $a['paid']);
        $this->assertEquals(6.0, $a['balance']);

        $this->assertEquals(24.0, $b['owed']);
        $this->assertEquals(10.0, $b['paid']);
        $this->assertEquals(14.0, $b['balance']);

        // No organization is left in the negative (the bug being fixed).
        $this->assertGreaterThanOrEqual(0, $a['balance']);
        $this->assertGreaterThanOrEqual(0, $b['balance']);
    }

    public function test_legacy_donations_without_a_snapshot_fall_back_to_the_live_formula(): void
    {
        $formula = $this->formula([
            ['organization' => 'Org A', 'percentage' => 10],
            ['organization' => 'Org B', 'percentage' => 90],
        ]);

        $this->donation($formula, 100);

        $rows = $this->allocations();

        $this->assertEquals(10.0, $rows[OrganizationPayout::makeKey('Org A')]['owed']);
        $this->assertEquals(90.0, $rows[OrganizationPayout::makeKey('Org B')]['owed']);
    }

    public function test_balance_guard_uses_the_snapshot_not_the_edited_formula(): void
    {
        $original = [['organization' => 'Org A', 'percentage' => 100]];
        $formula = $this->formula($original);

        $this->donation($formula, 100, $original);

        // Editing the live split to 50/50 must not claw back what Org A is owed.
        $formula->forceFill(['formula' => [
            ['organization' => 'Org A', 'percentage' => 50],
            ['organization' => 'Org B', 'percentage' => 50],
        ]])->save();
        $formula->refresh();

        $service = app(OrganizationPayoutService::class);
        $balance = $service->balance($formula, 'Org A');

        $this->assertEquals(100.0, $balance->owed);
        $this->assertEquals(0.0, $balance->paid);
        $this->assertEquals(100.0, $balance->balance);

        // The newly added org has no donation behind it.
        $this->assertEquals(0.0, $service->balance($formula, 'Org B')->balance);
    }

    public function test_totals_and_distribution_status_follow_the_snapshot(): void
    {
        $split = [
            ['organization' => 'Org A', 'percentage' => 50],
            ['organization' => 'Org B', 'percentage' => 50],
        ];
        $formula = $this->formula($split);
        $this->donation($formula, 100, $split);

        $service = app(OrganizationPayoutService::class);
        $this->assertSame(100.0, $service->totalOwed());
        $this->assertSame([$formula->id => 'pending'], $service->distributionStatuses([$formula->id]));

        $this->payout($formula, 'Org A', 40);

        // Editing the split must not change the totals already generated.
        $formula->forceFill(['formula' => [
            ['organization' => 'Org A', 'percentage' => 90],
            ['organization' => 'Org B', 'percentage' => 10],
        ]])->save();

        $this->assertSame(100.0, $service->totalOwed());
        $this->assertSame([$formula->id => 'partial'], $service->distributionStatuses([$formula->id]));
    }
}
