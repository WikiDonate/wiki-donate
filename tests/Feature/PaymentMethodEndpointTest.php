<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /v1/payment-methods registry endpoint is the single source that drives
 * every method-aware UI (donor pickers + admin filters/badges/charts).
 *
 * These tests pin the contract the SPA relies on: only active rows are
 * exposed, ordering follows sort_order, and the income/payout split is
 * derived from the row `type` so an inactive method can never leak into the
 * donor flow while webhooks for it keep working server-side.
 */
class PaymentMethodEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_seeded_active_methods_only(): void
    {
        $response = $this->getJson('/api/v1/payment-methods');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $codes = array_column($response->json('data.methods'), 'code');

        // Migration seeds paypal (active) + stripe (inactive).
        $this->assertSame(['paypal'], $codes);

        // Stripe is persisted for in-flight webhook handling but must never be
        // offered to donors while active=0.
        $this->assertDatabaseHas('payment_methods', ['code' => 'stripe', 'active' => false]);
    }

    public function test_active_but_unbound_methods_are_hidden_from_the_public_registry(): void
    {
        // A row with no provider class is a deployment mistake: it must never
        // be offered to donors, no matter what its `active` flag says.
        PaymentMethod::create([
            'code' => 'bkash',
            'label' => 'bKash',
            'type' => PaymentMethod::TYPE_INCOME,
            'active' => true,
            'sort_order' => 0,
        ]);

        $codes = array_column($this->getJson('/api/v1/payment-methods')->json('data.methods'), 'code');
        $this->assertSame(['paypal'], $codes);

        // The admin registry still shows it (with its real state) so the
        // misconfiguration is visible instead of silently disappearing.
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin']));

        $adminCodes = array_column(
            $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test')->plainTextToken)
                ->getJson('/api/v1/admin/payment-methods')
                ->assertOk()
                ->json('data.methods'),
            'code'
        );

        // Ordered by sort_order across the FULL registry (inactive included).
        $this->assertSame(['bkash', 'paypal', 'stripe'], $adminCodes);
    }

    public function test_payout_list_only_contains_payout_capable_methods(): void
    {
        // Stripe is a bound, income-only method; once active it is servable.
        PaymentMethod::query()->where('code', 'stripe')->update(['active' => true]);

        $data = $this->getJson('/api/v1/payment-methods')->json('data');

        $payoutCodes = array_column($data['payout_methods'], 'code');
        $incomeCodes = array_column($data['methods'], 'code');

        // paypal is `both` → in both lists; stripe is income-only → income only.
        $this->assertSame(['paypal'], $payoutCodes);
        $this->assertContains('stripe', $incomeCodes);
        $this->assertNotContains('stripe', $payoutCodes);
    }

    public function test_payload_exposes_capabilities_without_secrets(): void
    {
        $paypal = collect($this->getJson('/api/v1/payment-methods')->json('data.methods'))
            ->firstWhere('code', 'paypal');

        $this->assertSame('PayPal', $paypal['label']);
        $this->assertSame('both', $paypal['type']);
        $this->assertTrue($paypal['capabilities']['income']);
        $this->assertTrue($paypal['capabilities']['payout']);

        // config (currencies, mode, min_amount, webhook route) is server-side
        // metadata and must not be serialised to the client.
        $this->assertArrayNotHasKey('config', $paypal);
        $this->assertStringNotContainsString('secret', json_encode($paypal));
    }
}
