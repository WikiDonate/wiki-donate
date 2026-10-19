<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
