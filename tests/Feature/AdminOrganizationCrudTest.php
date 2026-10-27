<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin organization create/delete endpoints.
 *
 * Covers the server half of the Add-organization modal:
 *  - POST /admin/organizations validates name/EIN/email/methods
 *  - normalized_name is always derived server-side
 *  - EIN and normalized-name uniqueness return field errors
 *  - PUT /admin/organizations/{id} can edit every registry field, keeping
 *    uniqueness (excluding the row itself) and re-deriving normalized_name
 *  - DELETE refuses orgs with payout/formula history, allows clean deletes
 *  - both mutations are audited
 */
class AdminOrganizationCrudTest extends TestCase
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

    private function article(): Article
    {
        return Article::create([
            'slug' => 'crud-article-'.uniqid(),
            'title' => 'CRUD Article',
        ]);
    }

    public function test_admin_creates_org_with_methods_and_server_derived_name(): void
    {
        $response = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => '  Save   The Bees ',
                'ein' => '12-3456789',
                'city' => 'Austin',
                'state' => 'TX',
                'website' => 'bees.org',
                'paypal_email' => 'PAY@BEES.ORG',
                'payout_methods' => ['paypal'],
                'normalized_name' => 'hacker supplied',
            ]);

        $response->assertCreated();

        $org = Organization::firstOrFail();
        $this->assertSame('Save The Bees', $org->name);
        $this->assertSame('save the bees', $org->normalized_name);
        $this->assertSame('123456789', $org->ein);
        $this->assertSame('pay@bees.org', $org->paypal_email);
        $this->assertSame(['paypal'], $org->payoutMethods());
        $this->assertSame('unverified', $org->payout_status);
        $this->assertSame($this->admin->id, $org->created_by_id);

        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.created']);
    }

    public function test_create_defaults_to_paypal_payout_rail(): void
    {
        $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', ['name' => 'Default Rail Org'])
            ->assertCreated();

        $this->assertSame(['paypal'], Organization::firstOrFail()->payoutMethods());
    }

    public function test_create_from_search_result_persists_registry_fields(): void
    {
        // Modal flow: admin picks a row from /charities/search (which carries
        // ein/city/state/website) and submits it with the admin-only payout
        // additions. Everything except normalized_name comes through as-is.
        $searchMatch = [
            'name' => 'Search Picked Charity',
            'ein' => '12-3456789',
            'city' => 'Denver',
            'state' => 'CO',
            'website' => 'https://search-picked.example.org',
        ];

        $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                ...$searchMatch,
                'paypal_email' => 'give@search-picked.example.org',
                'payout_methods' => ['paypal'],
            ])
            ->assertCreated();

        $org = Organization::firstOrFail();
        $this->assertSame('Search Picked Charity', $org->name);
        $this->assertSame('123456789', $org->ein);
        $this->assertSame('Denver', $org->city);
        $this->assertSame('CO', $org->state);
        $this->assertSame('https://search-picked.example.org', $org->website);
        $this->assertSame(['paypal'], $org->payoutMethods());
    }

    public function test_create_manual_entry_without_ein_is_allowed(): void
    {
        // No search match -> admin types the name manually and leaves EIN
        // blank. The org is still creatable (unverified custom org), and the
        // registry-derived normalized_name is produced server-side.
        $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => 'Hand Typed  Local Fund',
                'payout_methods' => ['paypal'],
            ])
            ->assertCreated();

        $org = Organization::firstOrFail();
        $this->assertSame('Hand Typed Local Fund', $org->name);
        $this->assertSame('hand typed local fund', $org->normalized_name);
        $this->assertNull($org->ein);
        $this->assertSame('unverified', $org->payout_status);
    }

    public function test_create_rejects_duplicate_ein_and_name_and_bad_email(): void
    {
        Organization::create(['name' => 'Existing Org', 'ein' => '555555555']);

        $byEin = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => 'New Name',
                'ein' => '555555555',
            ]);
        $byEin->assertStatus(422);
        $this->assertArrayHasKey('ein', $byEin->json('field_errors'));

        $byName = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', ['name' => ' existing   org ']);
        $byName->assertStatus(422);
        $this->assertArrayHasKey('name', $byName->json('field_errors'));

        $badEmail = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => 'Email Org',
                'paypal_email' => 'not-an-email',
            ]);
        $badEmail->assertStatus(422);

        $this->assertSame(1, Organization::count());
    }

    public function test_create_rejects_inactive_or_unknown_payout_method(): void
    {
        // stripe is seeded inactive/income-only.
        $response = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => 'Bad Rail Org',
                'payout_methods' => ['stripe'],
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('payout_methods', $response->json('field_errors'));
        $this->assertSame(0, Organization::count());
    }

    public function test_admin_can_update_every_registry_field(): void
    {
        $org = Organization::create([
            'name' => 'Old Name',
            'ein' => '111111111',
            'website' => 'https://old.example',
            'city' => 'Old City',
            'state' => 'NY',
            'country' => 'US',
            'paypal_email' => 'old@example.org',
            'payout_methods' => ['paypal'],
        ]);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'name' => '  New   Name ',
                'ein' => '22-2222222',
                'website' => 'https://new.example',
                'city' => 'Austin',
                'state' => 'TX',
                'country' => 'US',
                'paypal_email' => 'NEW@EXAMPLE.ORG',
                'payout_methods' => ['paypal'],
            ]);

        $response->assertOk();

        $org->refresh();
        $this->assertSame('New Name', $org->name);
        $this->assertSame('new name', $org->normalized_name);
        $this->assertSame('222222222', $org->ein);
        $this->assertSame('https://new.example', $org->website);
        $this->assertSame('Austin', $org->city);
        $this->assertSame('TX', $org->state);
        $this->assertSame('new@example.org', $org->paypal_email);
        $this->assertSame($this->admin->id, $org->updated_by_id);

        // The audit row carries the full before/after, not just the email.
        $log = TransactionLog::where('event', 'organization.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Old Name', $log->before['name']);
        $this->assertSame('New Name', $log->after['name']);
        $this->assertSame('111111111', $log->before['ein']);
        $this->assertSame('222222222', $log->after['ein']);
        $this->assertSame('https://old.example', $log->before['website']);
    }

    public function test_update_can_clear_optional_fields(): void
    {
        $org = Organization::create([
            'name' => 'Clearable Org',
            'ein' => '121212121',
            'website' => 'https://clear.example',
            'city' => 'Miami',
            'payout_methods' => ['paypal'],
        ]);

        $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'ein' => '',
                'website' => '',
                'city' => null,
            ])
            ->assertOk();

        $org->refresh();
        $this->assertNull($org->ein);
        $this->assertNull($org->website);
        $this->assertNull($org->city);
        // Name is untouched by a partial update.
        $this->assertSame('Clearable Org', $org->name);
    }

    public function test_update_rejects_duplicate_name_and_ein_but_allows_its_own_values(): void
    {
        Organization::create(['name' => 'Taken Org', 'ein' => '333333333']);

        $org = Organization::create(['name' => 'Mine Org', 'ein' => '444444444']);

        // Name collision is compared on the normalized form (case + spacing).
        $byName = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['name' => ' taken   org ']);
        $byName->assertStatus(422);
        $this->assertArrayHasKey('name', $byName->json('field_errors'));

        // EIN collision ignores formatting: stored 33-3333333 vs typed 333333333.
        $byEin = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['ein' => '33-3333333']);
        $byEin->assertStatus(422);
        $this->assertArrayHasKey('ein', $byEin->json('field_errors'));

        // Resubmitting the row's own identity must not trip the checks.
        $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'name' => 'Mine Org',
                'ein' => '44-4444444',
            ])
            ->assertOk();

        $this->assertSame('Mine Org', $org->refresh()->name);
        $this->assertSame('444444444', $org->ein);
    }

    public function test_renaming_keeps_verification_but_email_change_resets_it(): void
    {
        $org = Organization::create([
            'name' => 'Verified Org',
            'ein' => '555555555',
            'paypal_email' => 'pay@example.org',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'name' => 'Renamed Verified Org',
                'website' => 'https://renamed.example',
            ])
            ->assertOk();

        $org->refresh();
        $this->assertSame('verified', $org->payout_status);
        $this->assertNotNull($org->verified_at);

        // The destination changed, so verification must be re-earned.
        $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}", [
                'paypal_email' => 'other@example.org',
            ])
            ->assertOk();

        $org->refresh();
        $this->assertSame('unverified', $org->payout_status);
        $this->assertNull($org->verified_at);
    }

    public function test_create_rejects_ein_that_only_differs_by_a_leading_zero(): void
    {
        Organization::create([
            'name' => 'Save the Children Federation',
            'ein' => '060726487',
        ]);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->postJson('/api/v1/admin/organizations', [
                'name' => 'Save The Children Federation Inc',
                'ein' => '60726487',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('ein', $response->json('field_errors'));
    }

    public function test_delete_refuses_when_payout_history_exists(): void
    {
        $org = Organization::create(['name' => 'Paid Org', 'ein' => '666666666']);
        $article = $this->article();

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'formula' => [
                ['organization' => 'Paid Org', 'organization_id' => $org->id, 'percentage' => 100],
            ],
        ]);

        OrganizationPayout::create([
            'donation_formula_id' => $formula->id,
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'organization_key' => 'paid-org',
            'amount' => 10,
            'currency' => 'usd',
            'type' => 'payout',
            'actor_id' => $this->admin->id,
        ]);

        $this->withHeaders($this->headersFor($this->admin))
            ->deleteJson("/api/v1/admin/organizations/{$org->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }

    public function test_delete_refuses_when_formula_references_exist(): void
    {
        $org = Organization::create(['name' => 'Formula Org', 'ein' => '777777777']);
        $article = $this->article();

        DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'formula' => [
                ['organization' => 'Formula Org', 'organization_id' => $org->id, 'percentage' => 100],
            ],
        ]);

        $this->withHeaders($this->headersFor($this->admin))
            ->deleteJson("/api/v1/admin/organizations/{$org->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }

    public function test_delete_removes_unreferenced_org_and_audits(): void
    {
        $org = Organization::create(['name' => 'Clean Org', 'ein' => '888888888']);

        $this->withHeaders($this->headersFor($this->admin))
            ->deleteJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk();

        $this->assertDatabaseMissing('organizations', ['id' => $org->id]);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.deleted']);
    }

    public function test_non_admin_cannot_create_or_delete(): void
    {
        Role::firstOrCreate(['name' => 'Editor']);
        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        $org = Organization::create(['name' => 'Protected Org', 'ein' => '999999999']);

        $this->withHeaders($this->headersFor($editor))
            ->postJson('/api/v1/admin/organizations', ['name' => 'Nope Org'])
            ->assertForbidden();

        $this->withHeaders($this->headersFor($editor))
            ->deleteJson("/api/v1/admin/organizations/{$org->id}")
            ->assertForbidden();
    }
}
