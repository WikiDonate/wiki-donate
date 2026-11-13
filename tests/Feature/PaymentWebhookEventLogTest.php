<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentWebhookEvent;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\Payment\PaymentCredentials;
use App\Services\Payment\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Durable webhook log + idempotency.
 *
 * The critical regression: dedupe used to live in the file cache, which
 * `php artisan cache:clear` (run by every deploy) wipes. A PayPal
 * retransmission after a release was then re-applied. These tests flush the
 * cache between deliveries to prove the unique (provider, event_id) key now
 * carries idempotency instead.
 */
class PaymentWebhookEventLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function mockPayPalClient(bool $verified = true): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);
        $mock->shouldReceive('getAccessToken')->andReturn('test-access-token');
        $mock->shouldReceive('verifyWebhook')->andReturn($verified);
        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    private function paypalHeaders(string $signature = 'test-signature'): array
    {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => $signature,
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-09-26T10:00:00Z',
        ];
    }

    private function postWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $headers = $signature === null
            ? $this->paypalHeaders()
            : $this->paypalHeaders($signature);

        return $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            $headers,
            json_encode($payload)
        );
    }

    private function pendingPayout(string $batchId = 'BATCH-1', string $itemId = 'ITEM-1'): OrganizationPayout
    {
        $actor = User::factory()->create();
        $article = Article::create(['slug' => 'webhook-log-'.uniqid(), 'title' => 'Webhook Log Article']);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $actor->id,
            'name' => 'Webhook Log Formula',
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

    private function itemSucceededPayload(OrganizationPayout $payout, string $eventId): array
    {
        return [
            'id' => $eventId,
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => [
                'payout_item_id' => $payout->payout_item_id,
                'sender_item_id' => $payout->uuid,
                'transaction_status' => 'SUCCESS',
            ],
        ];
    }

    public function test_processed_delivery_is_recorded_and_linked_to_the_payout(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $this->postWebhook($this->itemSucceededPayload($payout, 'WH_LOG_1'))->assertOk();

        $record = PaymentWebhookEvent::where('event_id', 'WH_LOG_1')->first();

        $this->assertNotNull($record);
        $this->assertSame('paypal', $record->provider);
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $record->status);
        $this->assertSame('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $record->event_type);
        $this->assertTrue($record->signature_valid);
        $this->assertSame(200, $record->http_status);
        $this->assertSame(OrganizationPayout::class, $record->related_type);
        $this->assertSame($payout->id, $record->related_id);
        $this->assertNotNull($record->processed_at);
    }

    /**
     * THE regression this feature exists for: cache is wiped (as every deploy
     * does) between two identical deliveries, and the second must still be
     * recognised as a duplicate.
     */
    public function test_duplicate_delivery_is_ignored_after_cache_flush(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();
        $payload = $this->itemSucceededPayload($payout, 'WH_LOG_DUPE');

        $this->postWebhook($payload)->assertOk();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(1, TransactionLog::where('event', 'payout.synced')->count());

        // Simulates `php artisan cache:clear` on deploy.
        Cache::flush();

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJson(['message' => 'Event already processed']);

        $this->assertSame(1, PaymentWebhookEvent::where('event_id', 'WH_LOG_DUPE')->count());
        $this->assertSame(1, TransactionLog::where('event', 'payout.synced')->count());
    }

    public function test_invalid_signature_is_recorded_as_failed_without_mutating_the_payout(): void
    {
        $this->mockPayPalClient(false);
        $payout = $this->pendingPayout();

        $this->postWebhook($this->itemSucceededPayload($payout, 'WH_LOG_BAD_SIG'))
            ->assertStatus(401);

        $record = PaymentWebhookEvent::where('event_id', 'WH_LOG_BAD_SIG')->first();

        $this->assertNotNull($record);
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, $record->status);
        $this->assertFalse($record->signature_valid);
        $this->assertSame(401, $record->http_status);
        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_failed_delivery_is_reopened_so_a_gateway_retry_can_succeed(): void
    {
        $payout = $this->pendingPayout();
        $payload = $this->itemSucceededPayload($payout, 'WH_LOG_RETRY');

        $this->mockPayPalClient(false);
        $this->postWebhook($payload)->assertStatus(401);
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, PaymentWebhookEvent::where('event_id', 'WH_LOG_RETRY')->value('status'));

        // PayPal retries; verification now passes.
        $this->mockPayPalClient(true);
        $this->postWebhook($payload)->assertOk();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, PaymentWebhookEvent::where('event_id', 'WH_LOG_RETRY')->value('status'));
        $this->assertSame(1, PaymentWebhookEvent::where('event_id', 'WH_LOG_RETRY')->count());
    }

    public function test_signature_material_is_hashed_and_raw_headers_are_not_stored(): void
    {
        $this->mockPayPalClient();
        $payout = $this->pendingPayout();

        $this->postWebhook(
            $this->itemSucceededPayload($payout, 'WH_LOG_HEADERS'),
            'super-secret-signature'
        )->assertOk();

        $headers = PaymentWebhookEvent::where('event_id', 'WH_LOG_HEADERS')->first()->headers;

        $this->assertStringStartsWith('sha256:', $headers['paypal-transmission-sig']);
        $this->assertStringNotContainsString('super-secret-signature', json_encode($headers));
        // The full header bag (cookies, authorization, ...) must never land here.
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertArrayNotHasKey('authorization', $headers);
    }

    public function test_stripe_delivery_without_a_webhook_secret_is_recorded_as_ignored(): void
    {
        $credentials = Mockery::mock(PaymentCredentials::class);
        $credentials->shouldReceive('get')->andReturn('');
        $this->app->instance(PaymentCredentials::class, $credentials);

        $payload = ['id' => 'evt_stripe_1', 'type' => 'checkout.session.completed'];

        $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'Stripe-Signature' => 'sig'],
            json_encode($payload)
        )->assertStatus(503);

        $record = PaymentWebhookEvent::where('event_id', 'evt_stripe_1')->first();

        $this->assertNotNull($record);
        $this->assertSame('stripe', $record->provider);
        $this->assertSame(PaymentWebhookEvent::STATUS_IGNORED, $record->status);
        $this->assertSame(503, $record->http_status);
    }

    public function test_prune_deletes_old_processed_rows_but_keeps_failures(): void
    {
        $make = function (string $id, string $status, int $daysAgo): PaymentWebhookEvent {
            $row = PaymentWebhookEvent::create([
                'provider' => 'paypal',
                'event_id' => $id,
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'status' => $status,
                'received_at' => now()->subDays($daysAgo),
            ]);
            $row->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

            return $row;
        };

        $make('old-processed', PaymentWebhookEvent::STATUS_PROCESSED, 120);
        $make('old-ignored', PaymentWebhookEvent::STATUS_IGNORED, 120);
        $make('old-failed', PaymentWebhookEvent::STATUS_FAILED, 120);
        $make('fresh-processed', PaymentWebhookEvent::STATUS_PROCESSED, 2);

        $this->artisan('webhooks:prune --days=90')->assertSuccessful();

        $this->assertDatabaseMissing('payment_webhook_events', ['event_id' => 'old-processed']);
        $this->assertDatabaseMissing('payment_webhook_events', ['event_id' => 'old-ignored']);
        $this->assertDatabaseHas('payment_webhook_events', ['event_id' => 'old-failed']);
        $this->assertDatabaseHas('payment_webhook_events', ['event_id' => 'fresh-processed']);
    }
}
