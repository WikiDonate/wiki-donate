<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Webhook reconciliation tests for Payouts events — the authoritative
 * per-item status path that complements the polling fallback (syncPending).
 *
 * Event names MUST be the real ones PayPal sends (`PAYMENT.PAYOUTS-ITEM.*`
 * and `PAYMENT.PAYOUTSBATCH.*`). The app also accepts the `PAYOUTS.ITEM.*`
 * family, which is covered by the legacy-name test below.
 */
class PayPalPayoutWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function mockPayPalClient(): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);
        $mock->shouldReceive('getAccessToken')->andReturn('test-access-token');
        $mock->shouldReceive('verifyWebhook')->andReturn(true);
        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    private function paypalWebhookHeaders(string $transmissionId = 'payout-transmission'): array
    {
        return [
            'paypal-auth-algo' => ['SHA256withRSA'],
            'paypal-cert-url' => ['https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123'],
            'paypal-transmission-id' => [$transmissionId],
            'paypal-transmission-sig' => ['test-signature'],
            'paypal-transmission-time' => ['2026-09-26T10:00:00Z'],
        ];
    }

    private function pendingPayout(string $batchId = 'BATCH-1', string $itemId = 'ITEM-1'): OrganizationPayout
    {
        $actor = User::factory()->create();
        $article = Article::create(['slug' => 'payout-article-'.uniqid(), 'title' => 'Payout Article']);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $actor->id,
            'name' => 'Webhook Formula',
            'formula' => [['organization' => 'Charity A', 'percentage' => 100]],
        ]);
        $org = Organization::create([
            'name' => 'Charity A',
            'ein' => 'EIN-'.uniqid(),
            'paypal_email' => 'charitya@paypal.test',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        return OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_id' => $org->id,
            'organization_name' => 'Charity A',
            'organization_key' => OrganizationPayout::makeKey('Charity A'),
            'destination_paypal_email' => 'charitya@paypal.test',
            'amount' => 50,
            'currency' => 'usd',
            'type' => 'partial',
            'status' => 'pending',
            'actor_id' => $actor->id,
            'payment_method' => 'paypal',
            'payout_batch_id' => $batchId,
            'payout_item_id' => $itemId,
            'provider_status' => 'PENDING',
        ]);
    }

    private function postWebhook(array $payload): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );
    }

    public function test_item_succeeded_marks_payout_paid_and_logs(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'SUCCESS',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('organization_payouts', [
            'id' => $payout->id,
            'status' => 'paid',
            'provider_status' => 'SUCCESS',
        ]);
        $this->assertNotNull($payout->fresh()->paid_at);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.synced']);
        $this->assertEquals('paypal', TransactionLog::where('event', 'payout.synced')->latest('id')->first()->meta['payment_method']);
    }

    public function test_item_fee_is_recorded_from_the_webhook(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'SUCCESS',
                // The receiver pays the fee, so PayPal reports it here.
                'payout_item_fee' => ['currency' => 'USD', 'value' => '1.10'],
            ],
        ])->assertOk();

        $fresh = $payout->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals(1.10, (float) $fresh->fee_amount);
        $this->assertEquals(48.90, (float) $fresh->net_amount);
        $this->assertEquals(2.2, (float) $fresh->fee_percent);
        $this->assertSame('paypal', $fresh->fee_source);
    }

    public function test_item_denied_marks_payout_failed_with_reason_and_logs(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.DENIED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'DENIED',
                'errors' => ['message' => 'RECEIVER_UNREGISTERED: email not confirmed'],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('organization_payouts', [
            'id' => $payout->id,
            'status' => 'failed',
            // The true provider status is preserved (was hard-coded FAILED).
            'provider_status' => 'DENIED',
        ]);
        $reason = OrganizationPayout::find($payout->id)->failure_reason;
        $this->assertStringContainsString('RECEIVER_UNREGISTERED', $reason);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.failed']);
        // Relayed failure goes through record() which never throws.
        $this->assertEquals(1, TransactionLog::where('event', 'payout.failed')->count());
    }

    public function test_already_paid_row_is_never_flipped_by_later_failure(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();
        $payout->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_status' => 'SUCCESS'])->save();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYOUTS.ITEM.FAILED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'FAILED',
                'errors' => ['message' => 'late rejection'],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('organization_payouts', [
            'id' => $payout->id,
            'status' => 'paid',
            'provider_status' => 'SUCCESS',
        ]);
        $this->assertDatabaseMissing('transaction_logs', ['event' => 'payout.failed']);
    }

    public function test_batch_denied_marks_matching_rows_failed(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout('BATCH-DENIED');

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTSBATCH.DENIED',
            'resource' => [
                'payout_batch_id' => 'BATCH-DENIED',
                'batch_status' => 'DENIED',
                'errors' => ['message' => 'batch was denied'],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('organization_payouts', [
            'id' => $payout->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.failed']);
        $this->assertEquals('paypal', TransactionLog::where('event', 'payout.failed')->latest('id')->first()->meta['payment_method']);
    }

    public function test_item_unknown_payout_id_is_ignored_gracefully(): void
    {
        $this->mockPayPalClient();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => [
                'payout_item_id' => 'ITEM-NOPE',
                'sender_item_id' => 'uuid-nope',
                'transaction_status' => 'SUCCESS',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('organization_payouts', 0);
    }

    /**
     * The legacy PAYOUTS.ITEM.* family must keep working too — both naming
     * families in the wild are accepted.
     */
    public function test_legacy_payouts_item_naming_family_still_handled(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYOUTS.ITEM.SUCCEEDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'SUCCESS',
            ],
        ])->assertOk();

        $this->assertSame('paid', $payout->fresh()->status);
    }

    /**
     * A reversal arrives AFTER success: the row must be re-opened (reversed)
     * rather than ignored, otherwise the balance stays consumed forever.
     */
    public function test_returned_after_success_reopens_balance(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();
        $payout->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_status' => 'SUCCESS'])->save();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.RETURNED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'RETURNED',
                'errors' => ['message' => 'receiver account closed'],
            ],
        ])->assertOk();

        $payout->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame('RETURNED', $payout->provider_status);
        $this->assertNotNull($payout->reversed_at);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'payout.reversed']);
    }

    public function test_unclaimed_item_is_treated_as_reversal(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.UNCLAIMED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'UNCLAIMED',
            ],
        ])->assertOk();

        $this->assertSame('reversed', $payout->fresh()->status);
    }

    /**
     * REFUNDED is a real PayPal item status and must re-open the balance like
     * RETURNED/UNCLAIMED did — it used to be ignored entirely.
     */
    public function test_refunded_after_success_reopens_balance(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();
        $payout->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_status' => 'SUCCESS'])->save();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.REFUNDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'REFUNDED',
            ],
        ])->assertOk();

        $payout->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame('REFUNDED', $payout->provider_status);
        $this->assertNotNull($payout->reversed_at);

        // A duplicate reversal is a no-op (PayPal retries deliveries).
        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.REFUNDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'REFUNDED',
            ],
        ])->assertOk();

        $this->assertSame(1, TransactionLog::where('event', 'payout.reversed')->count());
    }

    /**
     * A denied/blocked event arriving after a reversal must not flip the row
     * back to failed — the funds already came back, so that would corrupt the
     * history (and leave reversed_at set on a non-reversed row).
     */
    public function test_failure_event_does_not_override_a_reversed_row(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();
        $payout->forceFill([
            'status' => 'reversed',
            'reversed_at' => now(),
            'provider_status' => 'RETURNED',
        ])->save();

        $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.DENIED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'DENIED',
                'errors' => ['message' => 'late denial'],
            ],
        ])->assertOk();

        $payout->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertNotNull($payout->reversed_at);
    }
}
