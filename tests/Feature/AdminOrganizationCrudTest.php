<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
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
