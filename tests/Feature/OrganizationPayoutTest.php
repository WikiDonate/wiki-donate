<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
                ['organization' => 'Charity A', 'organization_id' => $this->org('Charity A'), 'ein' => '111111111', 'percentage' => 60],
                ['organization' => 'Charity B', 'organization_id' => $this->org('Charity B'), 'ein' => '222222222', 'percentage' => 40],
            ],
        ]);
    }

    /**
     * Fake the PayPal Payouts API. $status is the per-item transaction
     * status for both batch creation and batch retrieval; mutate
     * $this->payoutStatus between calls to simulate the transfer clearing.
     */
    private function fakePayPal(string $status = 'SUCCESS'): void
    {
        $this->payoutStatus = $status;

        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            // Batch creation (POST).
            '*/v1/payments/payouts' => function () {
                $status = $this->payoutStatus;

                return Http::response([
                    'batch_header' => [
                        'payout_batch_id' => 'BATCH-'.$status,
                        'batch_status' => 'PENDING',
                    ],
                    'items' => [[
                        'payout_item_id' => 'ITEM-'.$status,
                        'sender_item_id' => 'dummy',
                        'transaction_status' => $status,
                        'errors' => $status === 'FAILED' ? ['message' => 'RECEIVER_UNREGISTERED: email not confirmed'] : null,
                    ]],
                ]);
            },
            // Batch retrieval (GET /v1/payments/payouts/{id}).
            '*/v1/payments/payouts/*' => function () {
                $status = $this->payoutStatus;
                $payout = OrganizationPayout::query()->latest('id')->first();

                return Http::response([
                    'batch_header' => ['payout_batch_id' => 'BATCH-GET', 'batch_status' => 'SUCCESS'],
                    'items' => [[
                        'payout_item_id' => 'ITEM-GET',
                        'sender_item_id' => $payout?->uuid,
                        'transaction_status' => $status,
                        'errors' => $status === 'FAILED' ? ['message' => 'RECEIVER_UNREGISTERED: email not confirmed'] : null,
                    ]],
                ]);
            },
        ]);
    }

    protected string $payoutStatus = 'SUCCESS';

    /**
     * Create a verified org with a PayPal email — the state every payout
     * now requires before the ledger accepts a row.
     */
    private function org(string $name): int
    {
        $org = Organization::create([
            'name' => $name,
            'ein' => 'EIN-'.uniqid(),
            'paypal_email' => strtolower(str_replace(' ', '', $name)).'@paypal.test',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        return $org->id;
    }

    public function test_payout_is_blocked_for_unverified_organization(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

        // Re-create the formula with an org that has no verified destination.
        $formula = DonationFormula::create([
            'article_id' => $this->article->id,
            'user_id' => $this->admin->id,
            'name' => 'Unverified Formula',
            'formula' => [
                ['organization' => 'Unverified Org', 'organization_id' => null, 'ein' => null, 'percentage' => 100],
            ],
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Unverified Org',
            'amount' => 1,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('organization_payouts', [
            'organization_name' => 'Unverified Org',
        ]);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.blocked']);
    }

    public function test_payout_is_blocked_when_paypal_email_missing(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

        $org = Organization::create([
            'name' => 'No Email Org',
            'ein' => 'EIN-'.uniqid(),
            'paypal_email' => null,
            'payout_status' => 'unverified',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $this->article->id,
            'user_id' => $this->admin->id,
            'name' => 'No Email Formula',
            'formula' => [
                ['organization' => 'No Email Org', 'organization_id' => $org->id, 'ein' => null, 'percentage' => 100],
            ],
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'No Email Org',
            'amount' => 1,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('organization_payouts', [
            'organization_name' => 'No Email Org',
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
            'uuid' => (string) Str::uuid(),
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

        $this->assertEquals(600.0, $a['owed']);
        $this->assertEquals(0.0, $a['paid']);
        $this->assertEquals(600.0, $a['balance']);
        $this->assertEquals(400.0, $b['balance']);
    }

    public function test_overpay_is_rejected(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

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
        $this->fakePayPal();

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
        $this->assertEquals(0.0, $a['balance']);

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
        $this->fakePayPal();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/payouts/allocations')
            ->assertStatus(403);

        // Without auth, the role middleware throws UnauthorizedException::notLoggedIn() (403).
        // This is expected since stateful guard passes through Sanctum then hits the role gate.
        $this->withoutHeader('Authorization');
        $this->getJson('/api/v1/admin/payouts/allocations')
            ->assertStatus(403);
    }

    public function test_history_lists_payouts(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

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
        $this->assertEquals(25.0, $response->json('data.0.amount'));
    }

    public function test_payout_submits_real_paypal_transfer_and_is_paid(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('SUCCESS');

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertSame('paid', $response->json('data.status'));
        $this->assertNotNull($response->json('data.payout_batch_id'));
        $this->assertNotNull($response->json('data.payout_item_id'));
        $this->assertSame('SUCCESS', $response->json('data.provider_status'));

        // The real transfer was requested: PayPal Payouts API was hit with
        // the org's receiving email and the amount.
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v1/payments/payouts') {
                return false;
            }
            $body = $request->data();
            $item = $body['items'][0];

            return $item['receiver'] === 'charitya@paypal.test'
                && $item['amount']['value'] === '50.00'
                && $item['amount']['currency'] === 'USD';
        });
    }

    public function test_payout_stays_pending_until_paypal_confirms_and_sync_marks_paid(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('PENDING');

        // Submission accepted but transfer not cleared yet.
        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ]);
        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNull($response->json('data.paid_at'));
        $this->assertSame('PENDING', $response->json('data.provider_status'));
        $this->assertNotNull($response->json('data.payout_batch_id'));

        // While pending, the allocation keeps its balance reserved (no double pay).
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(10.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);

        // Sync reconciles: PayPal now reports item SUCCESS → paid.
        $this->payoutStatus = 'SUCCESS';
        $sync = $this->asAdmin()->postJson('/api/v1/admin/payouts/sync');
        $sync->assertOk();
        $this->assertCount(1, $sync->json('data'));
        $this->assertSame('paid', $sync->json('data.0.status'));
        $this->assertNotNull($sync->json('data.0.paid_at'));
    }

    public function test_payout_rejected_by_paypal_is_failed_and_off_balance(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('FAILED');

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        // PayPal rejected the item: the API surfaces the rejection but the
        // ledger row is kept (append-only) with status failed.
        $response->assertStatus(422);
        $this->assertDatabaseHas('organization_payouts', [
            'organization_name' => 'Charity A',
            'status' => 'failed',
            'amount' => 50,
        ]);

        // Failed money never moved: balance is fully available again.
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(60.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }
}
