<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\Payment\PaymentCredentials;
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

        // PayPal credentials now come from the registry, not .env.
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

    public function test_payout_fails_and_keeps_no_pending_row_when_registry_credentials_are_missing(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

        // Simulate a registry row without credentials (fresh deploy / typo).
        PaymentMethod::query()
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->firstOrFail()
            ->forceFill(['credentials' => null])
            ->save();
        app(PaymentCredentials::class)->flush();

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 40,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422);

        // A configuration error means no request was sent: the ledger row must
        // be failed (reconcilable) rather than pending forever.
        $this->assertDatabaseHas('organization_payouts', [
            'organization_name' => 'Charity A',
            'status' => 'failed',
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
            'note' => 'Bank ref 123',
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
        // the org's receiving email and the NET amount (gross 50 − 5% fee).
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v1/payments/payouts') {
                return false;
            }
            $body = $request->data();
            $item = $body['items'][0];

            return $item['receiver'] === 'charitya@paypal.test'
                && $item['amount']['value'] === '47.50'
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
        $this->assertSame(1, $sync->json('meta.changed'));
        $this->assertSame(0, $sync->json('meta.errors'));
    }

    /**
     * A row whose submission outcome is ambiguous (connection died before a
     * batch id was recorded) must NEVER be released — the request may have
     * reached PayPal. Sync re-submits it with the SAME sender_batch_id so
     * PayPal dedupes it, which reconciles instead of double-paying.
     */
    public function test_stuck_pending_row_without_batch_id_is_resubmitted_with_the_same_batch_id(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('SUCCESS');

        // Simulate the stuck state directly: pending, no batch id, submitted
        // longer ago than the grace window.
        $payout = OrganizationPayout::create([
            'donation_formula_id' => $this->formula->id,
            'organization_id' => Organization::where('name', 'Charity A')->value('id'),
            'organization_name' => 'Charity A',
            'organization_key' => OrganizationPayout::makeKey('Charity A'),
            'amount' => 50,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'pending',
            'submitted_at' => now()->subHour(),
            'actor_id' => $this->admin->id,
            'destination_paypal_email' => 'charitya@paypal.test',
            'payment_method' => 'paypal',
        ]);

        $sync = $this->asAdmin()->postJson('/api/v1/admin/payouts/sync');
        $sync->assertOk();

        // Reconciled, not released: the row is never marked failed.
        $this->assertNotSame('failed', $payout->fresh()->status);
        $this->assertSame('paid', $payout->fresh()->status);

        // The recovery used the row's uuid as sender_batch_id (PayPal's dedupe
        // key), so a request that did reach PayPal cannot be paid twice.
        Http::assertSent(function ($request) use ($payout) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v1/payments/payouts')) {
                return false;
            }

            return ($request->data()['sender_batch_header']['sender_batch_id'] ?? null) === $payout->uuid;
        });

        // Gross stays reserved/consumed: exactly 60 owed − 50 paid = 10.
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(10.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }

    /**
     * A provider 5xx is explicitly retryable with the same sender_batch_id
     * (PayPal docs), so the row must stay pending — not be released as failed.
     */
    public function test_provider_5xx_keeps_the_payout_pending_for_retry(): void
    {
        $this->addDonation(100, 'completed');

        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            '*/v1/payments/payouts' => Http::response([
                'name' => 'INTERNAL_SERVER_ERROR',
                'message' => 'An internal server error occurred.',
            ], 503),
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNull($response->json('data.payout_batch_id'));

        // Balance is still reserved: releasing it would allow a double pay.
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(10.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }

    /**
     * A duplicate sender_batch_id error means PayPal already holds the batch.
     * The row reconciles to that batch (pending) instead of failing — failing
     * would invite a retry with a new id and a second real transfer.
     */
    public function test_duplicate_sender_batch_id_reconciles_to_the_original_batch(): void
    {
        $this->addDonation(100, 'completed');

        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            '*/v1/payments/payouts' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'message' => 'The request was unsuccessful.',
                'details' => [[
                    'field' => 'sender_batch_id',
                    'issue' => 'SENDER_BATCH_ID_ALREADY_EXISTS',
                ]],
                'links' => [[
                    'rel' => 'self',
                    'href' => 'https://api-m.sandbox.paypal.com/v1/payments/payouts/ORIGINALBATCH123',
                ]],
            ], 422),
        ]);

        $response = $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame('ORIGINALBATCH123', $response->json('data.payout_batch_id'));

        // Balance stays reserved until the original batch reports a status.
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(10.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }

    /**
     * Polling (not just the webhook) must catch reversals: a REFUNDED item
     * re-opens the balance instead of leaving it consumed forever.
     */
    public function test_sync_marks_pending_row_reversed_when_paypal_refunds(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('PENDING');

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        // PayPal reports the item as refunded on the next poll.
        $this->payoutStatus = 'REFUNDED';
        $sync = $this->asAdmin()->postJson('/api/v1/admin/payouts/sync');
        $sync->assertOk();
        $sync->assertJsonPath('data.0.status', 'reversed');

        $payout = OrganizationPayout::query()->latest('id')->first();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame('REFUNDED', $payout->provider_status);
        $this->assertNotNull($payout->reversed_at);

        // Balance is available again: 60 owed − 0 paid.
        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(60.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }

    /**
     * The scheduled `--recheck-paid` pass re-polls recently paid rows so a
     * late RETURNED is caught even when no webhook is delivered.
     */
    public function test_recheck_paid_option_reverses_a_late_return(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal('SUCCESS');

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 50,
            'currency' => 'usd',
        ])->assertCreated()->assertJsonPath('data.status', 'paid');

        // A normal sync ignores paid rows.
        $this->payoutStatus = 'RETURNED';
        $this->asAdmin()->postJson('/api/v1/admin/payouts/sync')->assertOk();
        $this->assertSame('paid', OrganizationPayout::query()->latest('id')->first()->status);

        // The deep pass catches it.
        $this->artisan('payouts:sync --recheck-paid')->assertSuccessful();

        $payout = OrganizationPayout::query()->latest('id')->first();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame('RETURNED', $payout->provider_status);
    }

    /**
     * The legacy-currency report is the audit trail for rows excluded from
     * USD balances; it is read-only.
     */
    public function test_legacy_currency_report_lists_non_usd_completed_donations(): void
    {
        $this->addDonation(100, 'completed');

        Donation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->admin->id,
            'donation_formula_id' => $this->formula->id,
            'amount' => 42.5,
            'currency' => 'eur',
            'status' => 'completed',
            'metadata' => ['source' => 'legacy'],
        ]);

        $this->artisan('donations:legacy-currency')
            ->expectsOutputToContain('legacy currency')
            ->expectsOutputToContain('EUR: 1 row(s), 42.50')
            ->assertSuccessful();

        // Read-only: the row is untouched.
        $this->assertDatabaseHas('donations', ['amount' => 42.5, 'currency' => 'eur', 'status' => 'completed']);
    }

    /**
     * Renaming an organization inside the formula must not detach its payout
     * history (which would re-open the whole balance and allow a double pay).
     */
    public function test_renamed_organization_keeps_its_paid_history(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 60,
            'currency' => 'usd',
        ])->assertCreated();

        // Rename the row in the formula, keeping the same organization_id.
        $orgId = Organization::where('name', 'Charity A')->value('id');
        $this->formula->update([
            'formula' => [
                ['organization' => 'Charity A (renamed)', 'organization_id' => $orgId, 'ein' => '111111111', 'percentage' => 60],
                ['organization' => 'Charity B', 'organization_id' => Organization::where('name', 'Charity B')->value('id'), 'ein' => '222222222', 'percentage' => 40],
            ],
        ]);

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $renamed = $allocations->firstWhere('organization_name', 'Charity A (renamed)');

        // Full 60 already paid: balance stays zero, no double payout.
        $this->assertEquals(0.0, $renamed['balance']);

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A (renamed)',
            'amount' => 1,
            'currency' => 'usd',
        ])->assertStatus(422);
    }

    /**
     * The platform is USD-only: a payout requested in another currency is
     * rejected rather than coerced, so amounts can never drift.
     */
    public function test_payout_rejects_non_usd_currency(): void
    {
        $this->addDonation(100, 'completed');

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 60,
            'currency' => 'EUR',
        ])->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $this->assertEquals(0, OrganizationPayout::count());
    }

    /**
     * Donations created before the USD-only rule may still carry another
     * currency; the balance read must surface every completed donation in the
     * single supported currency.
     */
    public function test_allocations_report_the_supported_currency(): void
    {
        $this->addDonation(100, 'completed');

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'))
            ->where('organization_name', 'Charity A');

        $this->assertCount(1, $allocations);
        $this->assertEquals('USD', $allocations->first()['currency']);
        $this->assertEquals(60.0, $allocations->first()['owed']);
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

        // The rejection is still audited with the PayPal-response status.
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.created']);
        $log = TransactionLog::where('event', 'payout.created')->latest('id')->first();
        $this->assertEquals('failed', $log->after['status']);
        $this->assertNotEmpty($log->after['provider_status']);
        $this->assertNotEmpty($log->after['failure_reason']);
    }

    /**
     * The platform is USD-only. Legacy completed donations in EUR/GBP must be
     * excluded from the balance, not silently summed as if they were dollars
     * (which would inflate `owed` and overpay).
     */
    public function test_legacy_non_usd_donations_are_excluded_from_balances(): void
    {
        $this->addDonation(100, 'completed');

        foreach (['eur' => 500, 'gbp' => 250] as $currency => $amount) {
            Donation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $this->admin->id,
                'donation_formula_id' => $this->formula->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'metadata' => ['source' => 'legacy'],
            ]);
        }

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));

        // Only the 100 USD donation counts: A gets 60%, not 60% of 850.
        $this->assertEquals(60.0, $allocations->firstWhere('organization_name', 'Charity A')['owed']);
        $this->assertEquals(40.0, $allocations->firstWhere('organization_name', 'Charity B')['owed']);
    }

    /**
     * The history endpoint must resolve an organization the same way balances
     * do (id OR key) so a rename does not blank the payout history panel.
     */
    public function test_payout_history_is_rename_safe(): void
    {
        $this->addDonation(100, 'completed');
        $this->fakePayPal();

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 25,
            'currency' => 'usd',
        ])->assertCreated();

        $orgId = Organization::where('name', 'Charity A')->value('id');

        // Rename inside the formula, keeping the registry id.
        $this->formula->update([
            'formula' => [
                ['organization' => 'Charity A (renamed)', 'organization_id' => $orgId, 'ein' => '111111111', 'percentage' => 60],
                ['organization' => 'Charity B', 'organization_id' => Organization::where('name', 'Charity B')->value('id'), 'ein' => '222222222', 'percentage' => 40],
            ],
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/payouts/history'.
            '?donation_formula_id='.$this->formula->id.
            '&organization='.urlencode('Charity A (renamed)'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(25.0, $response->json('data.0.amount'));

        // The ledger index filter must resolve the rename the same way.
        $index = $this->asAdmin()->getJson('/api/v1/admin/payouts'.
            '?donation_formula_id='.$this->formula->id.
            '&organization='.urlencode('Charity A (renamed)'));

        $index->assertOk();
        $this->assertSame(1, $index->json('data.total'));
    }

    /**
     * Duplicate organization rows collapse to one key, so the second
     * percentage would be silently unpayable. Reject the formula instead.
     */
    public function test_formula_rejects_duplicate_organization_rows(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/donation-formulas', [
            'article_slug' => $this->article->slug,
            'name' => 'Duplicate Org Formula',
            'formula' => [
                ['organization' => 'Charity A', 'percentage' => 30],
                // Same org, only casing/whitespace differs.
                ['organization' => '  charity   a ', 'percentage' => 30],
                ['organization' => 'Charity B', 'percentage' => 40],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('already listed on row 1', (string) $response->json('errors.0'));

        $this->assertDatabaseMissing('donation_formulas', ['name' => 'Duplicate Org Formula']);
    }
}
