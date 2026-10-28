<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organization payout-method storage + legacy confirmation cleanup.
 *
 * Covers the DB half of the admin org CRUD work:
 *  - `payout_methods` JSON column exists and defaults to ['paypal']
 *  - the retired `paypal_confirm_token` / `paypal_confirm_sent_at` columns
 *    are gone
 *  - admin update accepts only active, payout-capable methods
 *  - invalid/inactive methods are rejected
 */
class OrganizationPayoutMethodsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_legacy_confirm_columns_are_dropped(): void
    {
        $this->assertTrue(Schema::hasColumn('organizations', 'payout_methods'));
        $this->assertFalse(Schema::hasColumn('organizations', 'paypal_confirm_token'));
        $this->assertFalse(Schema::hasColumn('organizations', 'paypal_confirm_sent_at'));
    }

    public function test_org_defaults_to_paypal_payout_rail(): void
    {
        $org = Organization::create(['name' => 'Default Rail Org', 'ein' => '111111111']);

        $this->assertSame(['paypal'], $org->payoutMethods());
        $this->assertTrue($org->allowsPayoutMethod('paypal'));
        $this->assertTrue($org->allowsPayoutMethod('PayPal'));
        $this->assertFalse($org->allowsPayoutMethod('stripe'));
        $this->assertFalse($org->allowsPayoutMethod(null));
    }

    public function test_admin_can_select_payout_methods(): void
    {
        // Make stripe payout-capable + active for this test.
        PaymentMethod::where('code', 'stripe')->update(['active' => true, 'type' => 'both']);

        $org = Organization::create(['name' => 'Multi Rail Org', 'ein' => '222222222']);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'payout_methods' => ['paypal', 'stripe'],
            ]);

        $response->assertOk();
        $this->assertSame(['paypal', 'stripe'], $org->refresh()->payoutMethods());

        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.updated']);
    }

    public function test_admin_cannot_select_unknown_or_inactive_method(): void
    {
        $org = Organization::create(['name' => 'Bad Rail Org', 'ein' => '333333333']);

        // stripe is active=false / type income in the seeded registry.
        $response = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'payout_methods' => ['stripe'],
            ]);

        $response->assertStatus(422);
        $this->assertSame(['paypal'], $org->refresh()->payoutMethods());
    }

    public function test_empty_payout_method_selection_is_rejected(): void
    {
        $org = Organization::create(['name' => 'Empty Rail Org', 'ein' => '444444444']);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'payout_methods' => [],
            ]);

        $response->assertStatus(422);
    }
}
