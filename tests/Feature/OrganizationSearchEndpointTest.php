<?php

namespace Tests\Feature;

use App\Mail\CustomOrganizationAdminMail;
use App\Mail\CustomOrganizationUserMail;
use App\Models\Article;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Donor/editor formula organization autocomplete is registry-only.
 *
 * - editors and admins can search `organizations/search`
 * - results come exclusively from the admin-curated `organizations` table
 * - no external charity API (Charity Navigator / ProPublica) is ever called
 * - external `charities/search` stays admin-only
 */
class OrganizationSearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Editor']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->editor = User::factory()->create();
        $this->editor->assignRole('Editor');
    }

    private function search(User $user, string $query = 'red')
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/organizations/search?q='.urlencode($query));
    }

    private function article(): Article
    {
        return Article::create([
            'slug' => 'org-search-article-'.uniqid(),
            'title' => 'Org Search Article',
        ]);
    }

    public function test_editor_search_returns_registry_rows_only(): void
    {
        Organization::create([
            'name' => 'Red Cross Local',
            'ein' => '111111111',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);
        Organization::create([
            'name' => 'Red Crescent',
            'payout_status' => 'unverified',
        ]);
        Organization::create(['name' => 'Blue Shield', 'payout_status' => 'verified']);

        $response = $this->search($this->editor, 'red');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Red Cross Local', 'Red Crescent'], $names);

        $verified = collect($response->json('data'))->firstWhere('name', 'Red Cross Local');
        $this->assertEquals('verified', $verified['payout_status']);
        $this->assertTrue($verified['is_verified']);
        $this->assertEquals('local', $verified['source']);
        $this->assertNotNull($verified['organization_id']);

        $unverified = collect($response->json('data'))->firstWhere('name', 'Red Crescent');
        $this->assertEquals('unverified', $unverified['payout_status']);
        $this->assertFalse($unverified['is_verified']);
    }

    public function test_search_matches_by_ein(): void
    {
        Organization::create(['name' => 'Some Org', 'ein' => '530196605']);

        $this->search($this->editor, '530196605')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Some Org');
    }

    public function test_no_external_charity_api_is_called_on_donor_path(): void
    {
        Http::fake();
        Organization::create(['name' => 'Red Cross Local']);

        $this->search($this->editor, 'red')->assertOk();

        Http::assertNothingSent();
    }

    public function test_short_query_returns_empty_without_touching_external_apis(): void
    {
        Http::fake();

        $this->search($this->editor, 'r')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Http::assertNothingSent();
    }

    public function test_guest_cannot_search_registry(): void
    {
        $this->getJson('/api/v1/organizations/search?q=red')->assertUnauthorized();
    }

    public function test_external_charities_search_stays_admin_only(): void
    {
        Http::fake();

        $editorToken = $this->editor->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$editorToken}")
            ->getJson('/api/v1/charities/search?q=red')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Registry rows are donor-visible immediately, verified or not
    // ---------------------------------------------------------------------

    public function test_admin_created_org_is_donor_visible_immediately_even_unverified(): void
    {
        // Admin curates a brand-new org straight into the registry (no
        // verification yet). It must be selectable in the formula autocomplete
        // the moment it exists — the badge, not the visibility, encodes state.
        Organization::create([
            'name' => 'Fresh Registry Org',
            'payout_status' => 'unverified',
        ]);

        $response = $this->search($this->editor, 'fresh registry');

        $response->assertOk()->assertJsonCount(1, 'data');
        $row = $response->json('data.0');
        $this->assertSame('Fresh Registry Org', $row['name']);
        $this->assertSame('unverified', $row['payout_status']);
        $this->assertFalse($row['is_verified']);
        $this->assertSame('local', $row['source']);
        $this->assertNotNull($row['organization_id']);
    }

    public function test_badge_state_follows_live_registry_verify_and_unverify(): void
    {
        $org = Organization::create([
            'name' => 'Live State Org',
            'ein' => '123456789',
            'payout_status' => 'unverified',
        ]);

        // Unverified registry row → red state.
        $row = $this->search($this->editor, 'live state')->json('data.0');
        $this->assertSame('unverified', $row['payout_status']);
        $this->assertFalse($row['is_verified']);

        // Verify in the registry → green state, immediately.
        $org->forceFill(['payout_status' => 'verified', 'verified_at' => now()])->save();
        $row = $this->search($this->editor, 'live state')->json('data.0');
        $this->assertSame('verified', $row['payout_status']);
        $this->assertTrue($row['is_verified']);

        // Unverify → back to red, no stale green.
        $org->forceFill(['payout_status' => 'unverified', 'verified_at' => null])->save();
        $row = $this->search($this->editor, 'live state')->json('data.0');
        $this->assertSame('unverified', $row['payout_status']);
        $this->assertFalse($row['is_verified']);
    }

    public function test_formula_list_badges_come_from_live_registry(): void
    {
        $org = Organization::create([
            'name' => 'Formula Badge Org',
            'ein' => '987654321',
            'payout_status' => 'unverified',
        ]);

        $create = $this->withHeader(
            'Authorization',
            'Bearer '.$this->editor->createToken('test')->plainTextToken,
        )->postJson('/api/v1/donation-formulas/', [
            'article_slug' => $this->article()->slug,
            'name' => 'Badge Formula',
            'formula' => [
                ['organization' => 'Formula Badge Org', 'ein' => '987654321', 'percentage' => 100],
            ],
        ]);

        $create->assertCreated();
        $slug = $create->json('data.article.slug') ?? Article::firstOrFail()->slug;

        // Article formula list is donor-facing and derives badges from the
        // live registry (not a snapshot taken at save time).
        $rows = $this->getJson("/api/v1/donation-formulas/article/{$slug}")->json('data.0.formula');
        $this->assertSame('unverified', $rows[0]['payout_status']);
        $this->assertFalse($rows[0]['is_verified']);

        $org->forceFill(['payout_status' => 'verified', 'verified_at' => now()])->save();

        $rows = $this->getJson("/api/v1/donation-formulas/article/{$slug}")->json('data.0.formula');
        $this->assertSame('verified', $rows[0]['payout_status']);
        $this->assertTrue($rows[0]['is_verified']);
    }

    // ---------------------------------------------------------------------
    // Custom free-text path still works and notifies
    // ---------------------------------------------------------------------

    public function test_custom_free_text_entry_still_creates_unverified_org_and_notifies(): void
    {
        Mail::fake();

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->editor->createToken('test')->plainTextToken,
        )->postJson('/api/v1/donation-formulas/', [
            'article_slug' => $this->article()->slug,
            'name' => 'Custom Org Formula',
            'formula' => [
                ['organization' => 'Totally Custom Fund', 'ein' => null, 'percentage' => 100],
            ],
        ]);

        $response->assertCreated();

        // Custom name becomes an unverified registry row (no EIN), and the
        // creator + admins are notified (queued, never blocking the save).
        $org = Organization::where('name', 'Totally Custom Fund')->firstOrFail();
        $this->assertSame('unverified', $org->payout_status);
        $this->assertNull($org->ein);

        Mail::assertQueued(CustomOrganizationUserMail::class, function ($mail) use ($org) {
            return $mail->organization->id === $org->id;
        });
        Mail::assertQueued(CustomOrganizationAdminMail::class, function ($mail) use ($org) {
            return $mail->organization->id === $org->id;
        });

        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.custom_notified']);
    }

    public function test_custom_entry_does_not_call_external_charity_apis(): void
    {
        Http::fake();
        Mail::fake();

        $this->withHeader(
            'Authorization',
            'Bearer '.$this->editor->createToken('test')->plainTextToken,
        )->postJson('/api/v1/donation-formulas/', [
            'article_slug' => $this->article()->slug,
            'name' => 'Offline Custom Formula',
            'formula' => [
                ['organization' => 'Offline Custom Org', 'ein' => null, 'percentage' => 100],
            ],
        ])->assertCreated();

        // Saving a custom name never triggers a directory lookup.
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Admin add-org modal keeps its external directory lookup
    // ---------------------------------------------------------------------

    public function test_admin_modal_search_still_hits_external_apis(): void
    {
        config(['services.charity_navigator.app_id' => null]);
        config(['services.charity_navigator.app_key' => null]);

        Http::fake([
            'projects.propublica.org/*' => Http::response([
                'organizations' => [
                    ['name' => 'American National Red Cross', 'ein' => 530196605, 'city' => 'Washington', 'state' => 'DC'],
                ],
            ], 200),
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$this->admin->createToken('test')->plainTextToken,
        )->getJson('/api/v1/charities/search?q=red%20cross');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'American National Red Cross')
            ->assertJsonPath('data.0.source', 'propublica');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'propublica'));
    }
}
