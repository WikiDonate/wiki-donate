<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationPayoutDestinationGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DonationFormula $formula;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $article = Article::create([
            'title' => 'Test Article',
            'slug' => 'test-article-'.uniqid(),
        ]);

        $this->formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Guard Formula',
            'formula' => [
                ['organization' => 'Guard Charity', 'organization_id' => null, 'percentage' => 100],
            ],
        ]);

        Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $this->formula->id,
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);
    }

    private function asAdmin(): self
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_payout_blocked_when_organization_not_registered(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Unknown Charity',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Organization is not allocated in this formula (anymore).']);
        $this->assertDatabaseCount('organization_payouts', 0);
    }

    public function test_payout_blocked_when_organization_unverified(): void
    {
        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'paypal_email' => 'finance@guard.example',
            'payout_status' => 'unverified',
        ]);

        $this->formula->update([
            'formula' => [
                ['organization' => 'Guard Charity', 'organization_id' => $organization->id, 'percentage' => 100],
            ],
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Guard Charity',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This organization is not verified yet. Verify it in the Organizations page before paying out.']);
        $this->assertDatabaseCount('organization_payouts', 0);

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'payout.blocked',
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_payout_blocked_when_paypal_email_missing(): void
    {
        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->formula->update([
            'formula' => [
                ['organization' => 'Guard Charity', 'organization_id' => $organization->id, 'percentage' => 100],
            ],
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Guard Charity',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This organization has no PayPal receiving email set. Set it in the Organizations page first.']);
        $this->assertDatabaseCount('organization_payouts', 0);
    }

    public function test_payout_succeeds_when_verified_and_paypal_email_present(): void
    {
        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'paypal_email' => 'finance@guard.example',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->formula->update([
            'formula' => [
                ['organization' => 'Guard Charity', 'organization_id' => $organization->id, 'percentage' => 100],
            ],
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Guard Charity',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('organization_payouts', 1);
        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'payout.created',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_payout_lookup_falls_back_to_name_when_organization_id_missing(): void
    {
        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'paypal_email' => 'finance@guard.example',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        // formula row does not include organization_id; name match should resolve.
        $this->assertNull($this->formula->formula[0]['organization_id']);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Guard Charity',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('organization_payouts', 1);
    }
}
