<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\PayPalPendingOrder;
use App\Models\User;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for frozen distribution formula snapshots (task 5646af26-1a1).
 *
 * Acceptance criteria:
 * - A donation captured via PayPal freezes the formula at payment time; later
 *   edits by the formula author must NOT change what /api/v1/report/donations
 *   returns for that donation.
 * - Legacy donations without a snapshot fall back to the live formula.
 */
class DonationReportFormulaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Role::firstOrCreate(['name' => 'Editor']);
    }

    /**
     * Mock the PayPalClient so no real API calls are made.
     */
    protected function mockPayPalClient(): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);

        $mock->shouldReceive('getAccessToken')->andReturn('test-access-token');
        $mock->shouldReceive('createOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_123',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=k8MQSfJ4'],
                ['rel' => 'capture', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_123/capture'],
            ],
        ]);
        $mock->shouldReceive('captureOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_123',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE_ABC',
                        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                        'create_time' => '2026-08-11T10:00:00Z',
                    ]],
                ],
            ]],
            'payer' => [
                'payer_id' => 'PAYER_XYZ',
                'email_address' => 'donor@example.com',
                'name' => ['given_name' => 'Test', 'surname' => 'Donor'],
            ],
        ]);
        $mock->shouldReceive('showOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_123',
            'status' => 'COMPLETED',
        ]);
        $mock->shouldReceive('verifyWebhook')->andReturn(true);

        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    /**
     * (a) Capture a donation, edit the formula afterwards, and confirm the
     * report still returns the ORIGINAL percentages frozen at payment time.
     */
    public function test_report_returns_frozen_snapshot_after_formula_edit(): void
    {
        $donor = User::factory()->create();
        $donor->assignRole('Editor'); // needed to edit own formula via role-gated route

        $article = Article::create([
            'slug' => 'snapshot-article-'.uniqid(),
            'title' => 'Snapshot Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $donor->id,
            'name' => 'Snapshot Formula',
            'formula' => [
                ['organization' => 'Wiki1', 'percentage' => 40],
                ['organization' => 'Wiki2', 'percentage' => 60],
            ],
            'details' => 'Monthly donation',
        ]);

        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Test Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
            'details' => 'Monthly donation',
        ]);

        $this->mockPayPalClient();

        $token = $donor->createToken('test')->plainTextToken;
        $auth = ['Authorization' => 'Bearer '.$token];

        // Capture — the formula snapshot is frozen into donations.metadata here
        $this->withHeaders($auth)
            ->postJson('/api/v1/paypal/capture-order', ['order_id' => 'PAYPAL_ORDER_123'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($donation);
        $this->assertSame([
            ['organization' => 'Wiki1', 'percentage' => 40],
            ['organization' => 'Wiki2', 'percentage' => 60],
        ], $donation->metadata['formula_snapshot']);

        // Formula author edits the formula to a different split afterwards
        $this->withHeaders($auth)
            ->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
                'name' => 'Snapshot Formula',
                'formula' => [
                    ['organization' => 'Wiki1', 'percentage' => 100],
                ],
                'details' => 'Monthly donation',
            ])
            ->assertOk();

        $formula->refresh();
        $this->assertCount(1, $formula->formula);
        $this->assertSame('Wiki1', $formula->formula[0]['organization']);
        $this->assertSame(100, $formula->formula[0]['percentage']);

        // The report must STILL show the original split from payment time
        $report = $this->withHeaders($auth)->getJson('/api/v1/report/donations');
        $report->assertOk()->assertJsonPath('success', true);

        $rows = collect($report->json('data.donations'));
        $row = $rows->firstWhere('id', $donation->id);

        $this->assertNotNull($row, 'donation row should be present in the report');
        $this->assertTrue($row['formula_snapshot_used']);
        $this->assertSame('Wiki1', $row['formula'][0]['organization']);
        $this->assertSame(40, $row['formula'][0]['percentage']);
        $this->assertSame('Wiki2', $row['formula'][1]['organization']);
        $this->assertSame(60, $row['formula'][1]['percentage']);
        $this->assertSame($formula->id, $row['formula_id']);
    }

    /**
     * (b) A legacy donation without a snapshot falls back to the live formula.
     */
    public function test_report_falls_back_to_live_formula_for_legacy_donation(): void
    {
        $donor = User::factory()->create();

        $article = Article::create([
            'slug' => 'legacy-article-'.uniqid(),
            'title' => 'Legacy Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $donor->id,
            'name' => 'Legacy Formula',
            'formula' => [
                ['organization' => 'Org A', 'percentage' => 70],
                ['organization' => 'Org B', 'percentage' => 30],
            ],
            'details' => 'Legacy donation',
        ]);

        // Legacy donation: no formula_snapshot in metadata
        $donation = Donation::create([
            'user_id' => $donor->id,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Test Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 10.00,
            'currency' => 'USD',
            'status' => 'completed',
            'metadata' => [
                'source' => 'paypal',
                'details' => 'Legacy donation',
            ],
        ]);

        $token = $donor->createToken('test')->plainTextToken;

        $report = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/report/donations');
        $report->assertOk()->assertJsonPath('success', true);

        $rows = collect($report->json('data.donations'));
        $row = $rows->firstWhere('id', $donation->id);

        $this->assertNotNull($row, 'donation row should be present in the report');
        $this->assertFalse($row['formula_snapshot_used']);
        $this->assertSame('Org A', $row['formula'][0]['organization']);
        $this->assertSame(70, $row['formula'][0]['percentage']);
    }
}
