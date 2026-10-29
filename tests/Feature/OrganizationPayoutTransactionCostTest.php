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
use App\Services\Payout\OrganizationPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Enforced 5% transaction-cost deduction on organization payouts.
 *
 * The admin always types the GROSS amount; the server deducts the configured
 * rate and transfers only the net. Nothing about the split may be supplied or
 * overridden by a client.
 */
class OrganizationPayoutTransactionCostTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $article;

    private $formula;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

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

        // Pin the rate so a future config change can't make these assertions
        // meaningless.
        config(['donations.transaction_cost_percent' => 5]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->article = Article::create([
            'title' => 'Fee Article',
            'slug' => 'fee-article-'.uniqid(),
            'is_active' => true,
        ]);

        $this->formula = DonationFormula::create([
            'article_id' => $this->article->id,
            'user_id' => $this->admin->id,
            'name' => 'Fee Formula',
            'formula' => [
                ['organization' => 'Charity A', 'organization_id' => $this->org('Charity A'), 'ein' => '111111111', 'percentage' => 100],
            ],
        ]);
    }

    private function org(string $name): int
    {
        return Organization::create([
            'name' => $name,
            'ein' => 'EIN-'.uniqid(),
            'paypal_email' => strtolower(str_replace(' ', '', $name)).'@paypal.test',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ])->id;
    }

    private function asAdmin(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->admin->createToken('test')->plainTextToken);
    }

    private function addDonation(float $amount): void
    {
        Donation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->admin->id,
            'donation_formula_id' => $this->formula->id,
            'amount' => $amount,
            'currency' => 'usd',
            'status' => 'completed',
            'metadata' => ['source' => 'test'],
        ]);
    }

    /** Fake PayPal; captures the submitted item amount so tests can assert the net. */
    private function fakePayPal(string $status = 'SUCCESS'): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            '*/v1/payments/payouts' => Http::response([
                'batch_header' => ['payout_batch_id' => 'BATCH-'.$status, 'batch_status' => 'PENDING'],
                'items' => [[
                    'payout_item_id' => 'ITEM-'.$status,
                    'sender_item_id' => 'dummy',
                    'transaction_status' => $status,
                    'errors' => $status === 'FAILED' ? ['message' => 'RECEIVER_UNREGISTERED'] : null,
                ]],
            ]),
        ]);
    }

    private function pay(array $overrides = [])
    {
        return $this->asAdmin()->postJson('/api/v1/admin/payouts', array_merge([
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 100,
            'currency' => 'usd',
        ], $overrides));
    }

    // ---------------------------------------------------------------- tests --

    public function test_gross_amount_is_split_into_forced_fee_and_net(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $response = $this->pay(['amount' => 100]);
        $response->assertCreated();

        $this->assertEquals(100.0, $response->json('data.amount'));
        $this->assertEquals(5.0, $response->json('data.fee_amount'));
        $this->assertEquals(5.0, $response->json('data.fee_percent'));
        $this->assertEquals(95.0, $response->json('data.net_amount'));

        $this->assertDatabaseHas('organization_payouts', [
            'organization_name' => 'Charity A',
            'amount' => 100,
            'fee_percent' => 5,
            'fee_amount' => 5,
            'net_amount' => 95,
        ]);
    }

    public function test_paypal_transfer_receives_net_amount_only(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->pay(['amount' => 200])->assertCreated();

        // Gross 200 → fee 10 → the organization is sent 190.00, never 200.
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v1/payments/payouts') {
                return false;
            }

            $item = $request->data()['items'][0];

            return $item['receiver'] === 'charitya@paypal.test'
                && $item['amount']['value'] === '190.00'
                && $item['amount']['currency'] === 'USD';
        });
    }

    public function test_client_supplied_net_or_fee_is_rejected(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        foreach ([
            ['net_amount' => 100],
            ['net' => 100],
            ['fee_amount' => 0],
            ['fee_percent' => 0],
            ['fee' => 0],
        ] as $payload) {
            $response = $this->pay(array_merge(['amount' => 100], $payload));
            $response->assertStatus(422);
        }

        $this->assertDatabaseCount('organization_payouts', 0);
    }

    public function test_service_rejects_client_net_even_when_bypassing_the_controller(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->expectException(\App\Exceptions\UserFacingException::class);

        app(OrganizationPayoutService::class)->createPayout([
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 100,
            'currency' => 'usd',
            'net_amount' => 100,
        ], $this->admin->id);
    }

    public function test_rounding_is_round_half_up_and_fee_plus_net_equals_gross(): void
    {
        $service = app(OrganizationPayoutService::class);

        // 0.05 * 5% = 0.0025 → 0.00 fee (half-up on the third decimal)
        $this->assertSame(['percent' => 5.0, 'fee' => 0.0, 'net' => 0.05], $service->splitTransactionCost(0.05));
        // 1.10 * 5% = 0.055 → 0.06 fee (half-up) and 1.04 net
        $this->assertSame(['percent' => 5.0, 'fee' => 0.06, 'net' => 1.04], $service->splitTransactionCost(1.10));
        // 0.10 * 5% = 0.005 → 0.01 fee (half-up), 0.09 net — no lost cent
        $this->assertSame(['percent' => 5.0, 'fee' => 0.01, 'net' => 0.09], $service->splitTransactionCost(0.10));

        foreach ([0.05, 0.10, 1.10, 7.77, 99.99, 1234.56] as $gross) {
            $split = $service->splitTransactionCost($gross);
            $this->assertEqualsWithDelta(
                $gross,
                $split['fee'] + $split['net'],
                0.0001,
                "fee + net must equal the gross amount for {$gross}"
            );
        }
    }

    public function test_net_below_provider_minimum_is_rejected(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        // Gross 1.00 → fee 0.05 → net 0.95, below the 1.00 minimum.
        $response = $this->pay(['amount' => 1.00]);
        $response->assertStatus(422);
        $this->assertStringContainsString('minimum', strtolower($response->json('message')));

        $this->assertDatabaseCount('organization_payouts', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payments/payouts'));
    }

    public function test_failed_payout_keeps_fee_breakdown_and_moves_no_money(): void
    {
        $this->addDonation(500);
        $this->fakePayPal('FAILED');

        $this->pay(['amount' => 100])->assertStatus(422);

        // Fee/net are recorded on the failed row (audit trail) but the gross
        // never leaves the balance: the org can be paid again in full.
        $this->assertDatabaseHas('organization_payouts', [
            'status' => 'failed',
            'amount' => 100,
            'fee_amount' => 5,
            'net_amount' => 95,
        ]);

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $this->assertEquals(500.0, $allocations->firstWhere('organization_name', 'Charity A')['balance']);
    }

    public function test_gross_still_consumes_the_owed_balance_not_the_net(): void
    {
        $this->addDonation(100);
        $this->fakePayPal();

        $this->pay(['amount' => 100])->assertCreated();

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));
        $a = $allocations->firstWhere('organization_name', 'Charity A');

        $this->assertEquals(100.0, (float) $a['paid']);
        $this->assertEquals(0.0, (float) $a['balance']);
    }

    public function test_transaction_log_records_gross_fee_net_and_rate(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->pay(['amount' => 100])->assertCreated();

        $log = TransactionLog::where('event', 'payout.created')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertEquals(100.0, (float) $log->after['amount']);
        $this->assertEquals(5.0, (float) $log->after['fee_amount']);
        $this->assertEquals(95.0, (float) $log->after['net_amount']);
        $this->assertEquals(5.0, (float) $log->after['fee_percent']);
        $this->assertEquals(5.0, (float) $log->meta['transaction_cost_percent']);
    }

    public function test_transactions_feed_exposes_fee_and_net_and_legacy_rows_read_gross(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->pay(['amount' => 100])->assertCreated();

        // A legacy row (pre-fee era) is backfilled gross: fee 0 / net == amount.
        OrganizationPayout::create([
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Legacy Org',
            'organization_key' => 'legacy org',
            'amount' => 42,
            'fee_percent' => 0,
            'fee_amount' => 0,
            'net_amount' => 42,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'paid',
            'paid_at' => now(),
            'actor_id' => $this->admin->id,
            'payment_method' => 'paypal',
        ]);

        $rows = collect($this->asAdmin()->getJson('/api/v1/admin/transactions?type=expense')->json('data'));

        $new = $rows->firstWhere('organization_name', 'Charity A');
        $this->assertEquals(5.0, $new['fee_amount']);
        $this->assertEquals(95.0, $new['net_amount']);
        $this->assertEquals(5.0, $new['fee_percent']);

        $legacy = $rows->firstWhere('organization_name', 'Legacy Org');
        $this->assertEquals(0.0, $legacy['fee_amount']);
        $this->assertEquals(42.0, $legacy['net_amount']);
        $this->assertEquals(42.0, $legacy['amount']);
    }

    public function test_summary_reports_fee_and_net_totals(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->pay(['amount' => 100])->assertCreated();

        $summary = $this->asAdmin()->getJson('/api/v1/admin/transactions/summary')->json('data');

        $this->assertEquals(100.0, (float) $summary['totalPayouts']);
        $this->assertEquals(5.0, (float) $summary['totalPayoutFees']);
        $this->assertEquals(95.0, (float) $summary['totalPayoutNet']);
        $this->assertEquals(5.0, (float) $summary['transactionCostPercent']);
    }

    public function test_csv_export_includes_fee_and_net_columns(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->pay(['amount' => 100])->assertCreated();

        $response = $this->asAdmin()->get('/api/v1/admin/transactions/export?type=expense');
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Fee', $csv);
        $this->assertStringContainsString('Net', $csv);
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringContainsString('-5.00', $csv);
        $this->assertStringContainsString('-95.00', $csv);
    }

    public function test_rate_comes_from_one_config_key(): void
    {
        config(['donations.transaction_cost_percent' => 10]);

        $this->addDonation(500);
        $this->fakePayPal();

        $response = $this->pay(['amount' => 100]);
        $response->assertCreated();

        $this->assertEquals(10.0, $response->json('data.fee_percent'));
        $this->assertEquals(10.0, $response->json('data.fee_amount'));
        $this->assertEquals(90.0, $response->json('data.net_amount'));
    }

    public function test_allocations_expose_the_forced_rate_for_the_pay_modal(): void
    {
        $this->addDonation(500);

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));

        $this->assertEquals(5.0, (float) $allocations->firstWhere('organization_name', 'Charity A')['transaction_cost_percent']);
    }
}
