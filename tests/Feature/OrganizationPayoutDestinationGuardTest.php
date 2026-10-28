<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $this->seedPayPalRegistryCredentials();

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

    /** PayPal credentials come from the registry (not .env). */
    private function seedPayPalRegistryCredentials(): void
    {
        PaymentMethod::query()
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->firstOrFail()
            ->forceFill([
                'credentials' => [
                    'client_id' => 'fake-client-id',
                    'client_secret' => 'fake-client-secret',
                ],
            ])
            ->save();

        app(PaymentCredentials::class)->flush();
    }

    /**
     * Hermetic PayPal stub: success-path tests must not depend on real
     * sandbox credentials (absent in CI), otherwise a provider rejection
     * correctly surfaces as 422 instead of the asserted 201.
     */
    private function fakePayPalSuccess(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            '*/v1/payments/payouts' => Http::response([
                'batch_header' => [
                    'payout_batch_id' => 'BATCH-SUCCESS',
                    'batch_status' => 'PENDING',
                ],
                'items' => [[
                    'payout_item_id' => 'ITEM-SUCCESS',
                    'sender_item_id' => 'dummy',
                    'transaction_status' => 'SUCCESS',
                    'errors' => null,
                ]],
            ]),
        ]);
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
        $this->assertEquals('paypal', TransactionLog::where('event', 'payout.blocked')->latest('id')->first()->meta['payment_method']);
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
        $this->fakePayPalSuccess();

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
        $this->fakePayPalSuccess();

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

    /**
     * The selected payout method is part of the gate: an org that did not
     * enable the requested rail cannot receive a payout through it.
     */
    public function test_payout_blocked_when_method_not_enabled_on_org(): void
    {
        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'paypal_email' => 'finance@guard.example',
            'payout_status' => 'verified',
            'verified_at' => now(),
            'payout_methods' => ['stripe'],
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
            'payment_method' => 'paypal',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This organization is not set up to receive paypal payouts. Enable that payout method in the Organizations page first.']);
        $this->assertDatabaseCount('organization_payouts', 0);

        $log = TransactionLog::where('event', 'payout.blocked')->latest('id')->first();
        $this->assertEquals('method_not_allowed', $log->meta['reason']);
        $this->assertEquals('paypal', $log->meta['payment_method']);
    }

    /**
     * The enabled rail is honoured end-to-end: a payout on an org whose
     * payout_methods include paypal goes through and records the rail.
     */
    public function test_payout_allowed_when_method_enabled_on_org(): void
    {
        $this->fakePayPalSuccess();

        $organization = Organization::create([
            'name' => 'Guard Charity',
            'normalized_name' => Organization::normalizeName('Guard Charity'),
            'paypal_email' => 'finance@guard.example',
            'payout_status' => 'verified',
            'verified_at' => now(),
            'payout_methods' => ['paypal'],
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
            'payment_method' => 'paypal',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organization_payouts', [
            'organization_id' => $organization->id,
            'payment_method' => 'paypal',
        ]);
    }
}
