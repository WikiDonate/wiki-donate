<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payment\PaymentCredentials;
use App\Services\Payout\OrganizationPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payout fees are provider-reported, not a fixed percentage.
 *
 * The admin types the GROSS amount, the batch sets `fees_payer=RECEIVER`, and
 * the actual PayPal fee (`payout_item_fee`) is stored on the row once known.
 * `payout_fee_estimate_percent` is a display-only estimate for the Pay modal.
 */
class OrganizationPayoutProviderFeeTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $article = Article::create([
            'title' => 'Provider Fee Article',
            'slug' => 'provider-fee-'.uniqid(),
            'is_active' => true,
        ]);

        $this->formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Provider Fee Formula',
            'formula' => [
                ['organization' => 'Charity A', 'organization_id' => $this->org(), 'ein' => '111111111', 'percentage' => 100],
            ],
        ]);
    }

    private function org(): int
    {
        return Organization::create([
            'name' => 'Charity A',
            'ein' => 'EIN-'.uniqid(),
            'paypal_email' => 'charitya@paypal.test',
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

    /**
     * @param  array{currency?: string, value?: string}|null  $itemFee
     * @param  array{currency?: string, value?: string}|null  $headerFee
     */
    private function fakePayPal(string $status = 'SUCCESS', ?array $itemFee = null, ?array $headerFee = null): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            '*/v1/payments/payouts' => Http::response(array_filter([
                'batch_header' => array_filter([
                    'payout_batch_id' => 'BATCH-'.$status,
                    'batch_status' => 'PENDING',
                    'fees' => $headerFee,
                ], fn ($value) => $value !== null),
                'items' => [array_filter([
                    'payout_item_id' => 'ITEM-'.$status,
                    'sender_item_id' => 'dummy',
                    'transaction_status' => $status,
                    'errors' => $status === 'FAILED' ? ['message' => 'RECEIVER_UNREGISTERED'] : null,
                    'payout_item_fee' => $itemFee,
                ], fn ($value) => $value !== null)],
            ], fn ($value) => $value !== null)),
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

    public function test_creation_sends_the_gross_amount_and_leaves_the_fee_pending(): void
    {
        $this->addDonation(500);
        // No fee in the provider response yet.
        $this->fakePayPal('PENDING');

        $response = $this->pay(['amount' => 100]);
        $response->assertCreated();

        $response->assertJsonPath('data.amount', 100);
        $response->assertJsonPath('data.fee_amount', null);
        $response->assertJsonPath('data.net_amount', null);
        $response->assertJsonPath('data.fee_percent', null);
        $response->assertJsonPath('data.fee_source', 'pending_provider');

        // The full gross is sent, with the receiver bearing the fee.
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v1/payments/payouts') {
                return false;
            }

            $body = $request->data();

            return $body['items'][0]['amount']['value'] === '100.00'
                && ($body['sender_batch_header']['fees_payer'] ?? null) === 'RECEIVER';
        });
    }

    public function test_provider_item_fee_is_recorded_as_fee_net_and_effective_percent(): void
    {
        $this->addDonation(500);
        $this->fakePayPal('SUCCESS', ['currency' => 'USD', 'value' => '2.50']);

        $response = $this->pay(['amount' => 100]);
        $response->assertCreated();

        $response->assertJsonPath('data.fee_amount', 2.5);
        $response->assertJsonPath('data.net_amount', 97.5);
        $response->assertJsonPath('data.fee_percent', 2.5);
        $response->assertJsonPath('data.fee_source', 'paypal');

        $this->assertDatabaseHas('organization_payouts', [
            'amount' => 100,
            'fee_amount' => 2.5,
            'net_amount' => 97.5,
            'fee_percent' => 2.5,
            'fee_source' => 'paypal',
        ]);
    }

    public function test_batch_header_fee_is_used_when_the_item_fee_is_absent(): void
    {
        $this->addDonation(500);
        $this->fakePayPal('SUCCESS', null, ['currency' => 'USD', 'value' => '3.00']);

        $this->pay(['amount' => 100])->assertCreated();

        $this->assertDatabaseHas('organization_payouts', [
            'amount' => 100,
            'fee_amount' => 3,
            'net_amount' => 97,
            'fee_percent' => 3,
            'fee_source' => 'paypal',
        ]);
    }

    public function test_sync_records_the_provider_fee_on_a_pending_row(): void
    {
        $this->addDonation(500);
        $payout = $this->pendingRow('BATCH-SYNC', 'ITEM-SYNC');

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            '*/v1/payments/payouts/*' => Http::response([
                'batch_header' => ['payout_batch_id' => 'BATCH-SYNC', 'batch_status' => 'SUCCESS'],
                'items' => [[
                    'payout_item_id' => 'ITEM-SYNC',
                    'sender_item_id' => $payout->uuid,
                    'transaction_status' => 'SUCCESS',
                    'payout_item_fee' => ['currency' => 'USD', 'value' => '1.10'],
                ]],
            ]),
        ]);

        $this->asAdmin()->postJson('/api/v1/admin/payouts/sync')->assertOk();

        $fresh = $payout->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals(1.10, (float) $fresh->fee_amount);
        $this->assertEquals(48.90, (float) $fresh->net_amount);
        $this->assertEquals(2.2, (float) $fresh->fee_percent);
        $this->assertSame('paypal', $fresh->fee_source);
    }

    public function test_client_supplied_fee_or_net_is_rejected(): void
    {
        $this->addDonation(500);
        $this->fakePayPal('SUCCESS', ['currency' => 'USD', 'value' => '1.00']);

        foreach ([
            ['net_amount' => 100],
            ['net' => 100],
            ['fee_amount' => 0],
            ['fee_percent' => 0],
            ['fee' => 0],
        ] as $payload) {
            $this->pay(array_merge(['amount' => 100], $payload))->assertStatus(422);
        }

        $this->assertDatabaseCount('organization_payouts', 0);
    }

    public function test_service_rejects_client_net_even_when_bypassing_the_controller(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $this->expectException(UserFacingException::class);

        app(OrganizationPayoutService::class)->createPayout([
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'amount' => 100,
            'currency' => 'usd',
            'net_amount' => 100,
        ], $this->admin->id);
    }

    public function test_gross_below_the_provider_minimum_is_rejected_before_sending(): void
    {
        $this->addDonation(500);
        $this->fakePayPal();

        $response = $this->pay(['amount' => 0.99]);
        $response->assertStatus(422);
        $this->assertStringContainsString('at least', strtolower($response->json('message')));

        $this->assertDatabaseCount('organization_payouts', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payments/payouts'));
    }

    public function test_fee_can_never_exceed_the_transfer(): void
    {
        $payout = $this->pendingRow('BATCH-CLAMP', 'ITEM-CLAMP');

        $applied = app(OrganizationPayoutService::class)->applyProviderFee($payout, [
            'currency' => 'USD',
            'value' => '150.00',
        ]);

        $this->assertTrue($applied);
        $fresh = $payout->fresh();
        $this->assertEquals(50.0, (float) $fresh->fee_amount);
        $this->assertEquals(0.0, (float) $fresh->net_amount);
    }

    public function test_allocations_expose_the_estimate_percent_for_the_pay_modal(): void
    {
        config(['donations.payout_fee_estimate_percent' => 2]);
        $this->addDonation(500);

        $allocations = collect($this->asAdmin()->getJson('/api/v1/admin/payouts/allocations')->json('data'));

        $this->assertEquals(2.0, (float) $allocations->firstWhere('organization_name', 'Charity A')['fee_estimate_percent']);
    }

    public function test_sync_parses_the_real_paypal_nested_item_shape(): void
    {
        $this->addDonation(500);
        $payout = $this->pendingRow('BATCH-NESTED', 'ITEM-NESTED');

        // Shape returned by GET /v1/payments/payouts/{id}?fields=items:
        // sender_item_id lives under payout_item, not at the item root.
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            '*/v1/payments/payouts/*' => Http::response([
                'batch_header' => [
                    'payout_batch_id' => 'BATCH-NESTED',
                    'batch_status' => 'SUCCESS',
                    'fees' => ['currency' => 'USD', 'value' => '0.19'],
                ],
                'items' => [[
                    'payout_item_id' => 'ITEM-NESTED',
                    'transaction_status' => 'SUCCESS',
                    'payout_item_fee' => ['currency' => 'USD', 'value' => '0.19'],
                    'payout_item' => [
                        'amount' => ['currency' => 'USD', 'value' => '50.00'],
                        'receiver' => 'charitya@paypal.test',
                        'sender_item_id' => $payout->uuid,
                    ],
                ]],
            ]),
        ]);

        $this->asAdmin()->postJson('/api/v1/admin/payouts/sync')->assertOk();

        $fresh = $payout->fresh();
        $this->assertSame('paid', $fresh->status, 'nested sender_item_id must be matched');
        $this->assertEquals(0.19, (float) $fresh->fee_amount);
        $this->assertEquals(49.81, (float) $fresh->net_amount);
        $this->assertSame('paypal', $fresh->fee_source);
    }

    private function pendingRow(string $batchId, string $itemId): OrganizationPayout
    {
        return OrganizationPayout::create([
            'donation_formula_id' => $this->formula->id,
            'organization_name' => 'Charity A',
            'organization_key' => OrganizationPayout::makeKey('Charity A'),
            'destination_paypal_email' => 'charitya@paypal.test',
            'amount' => 50,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'pending',
            'submitted_at' => now(),
            'actor_id' => $this->admin->id,
            'payment_method' => 'paypal',
            'payout_batch_id' => $batchId,
            'payout_item_id' => $itemId,
            'provider_status' => 'PENDING',
            'fee_source' => 'pending_provider',
        ]);
    }
}
