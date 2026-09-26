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
 * Webhook reconciliation tests for PAYOUTS.* events — the authoritative
 * per-item status path that complements the polling fallback (syncPending).
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
            'method' => 'paypal',
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
            'event_type' => 'PAYOUTS.ITEM.SUCCEEDED',
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
    }

    public function test_item_denied_marks_payout_failed_with_reason_and_logs(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYOUTS.ITEM.DENIED',
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
            'provider_status' => 'FAILED',
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
            'event_type' => 'PAYOUTS.BATCH.PROCESSING.DENIED',
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
    }

    public function test_item_unknown_payout_id_is_ignored_gracefully(): void
    {
        $this->mockPayPalClient();

        $response = $this->postWebhook([
            'id' => 'WH_'.uniqid(),
            'event_type' => 'PAYOUTS.ITEM.COMPLETED',
            'resource' => [
                'payout_item_id' => 'ITEM-NOPE',
                'sender_item_id' => 'uuid-nope',
                'transaction_status' => 'SUCCESS',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('organization_payouts', 0);
    }
}
