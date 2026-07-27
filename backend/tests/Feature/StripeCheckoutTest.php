<?php

namespace Tests\Feature;

use App\Models\Donation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Tests\TestCase;

class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip tests if no Stripe secret key is configured
        if (empty(config('services.stripe.secret'))) {
            $this->markTestSkipped('Stripe secret key not configured');
        }
    }

    /**
     * Test that the checkout endpoint validates required fields.
     */
    public function test_checkout_session_requires_amount(): void
    {
        $response = $this->postJson('/api/v1/stripe/checkout', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test that amount must be at least 0.50.
     */
    public function test_checkout_session_validates_minimum_amount(): void
    {
        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 0.25,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test that cause_id must exist if provided.
     */
    public function test_checkout_session_validates_cause_id(): void
    {
        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 5.00,
            'cause_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cause_id']);
    }

    /**
     * Test successful checkout session creation (mocked).
     */
    public function test_checkout_session_creates_successfully(): void
    {
        // This test requires Stripe API connectivity in test mode
        // In CI, you'd use a mock; here we skip if no key
        if (empty(config('services.stripe.secret')) || ! str_starts_with(config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('Requires Stripe test mode secret key');
        }

        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 10.00,
            'currency' => 'usd',
            'donor_name' => 'Test Donor',
            'donor_email' => 'test@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Checkout session created',
        ]);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['checkout_url', 'session_id'],
        ]);
    }

    /**
     * Test that currency is validated.
     */
    public function test_checkout_session_validates_currency_format(): void
    {
        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 5.00,
            'currency' => 'invalid_currency',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency']);
    }

    /**
     * Test guest (unauthenticated) checkout works.
     */
    public function test_guest_can_create_checkout_session(): void
    {
        if (empty(config('services.stripe.secret')) || ! str_starts_with(config('services.stripe.secret'), 'sk_test_')) {
            $this->markTestSkipped('Requires Stripe test mode secret key');
        }

        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 25.00,
            'donor_email' => 'guest@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test that donor_email is validated.
     */
    public function test_checkout_session_validates_donor_email(): void
    {
        $response = $this->postJson('/api/v1/stripe/checkout', [
            'amount' => 5.00,
            'donor_email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['donor_email']);
    }
}
