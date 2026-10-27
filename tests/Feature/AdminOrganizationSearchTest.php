<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Directory autocomplete used by the admin "Add organization" modal.
 *
 * The modal must never offer an organization that is already in the registry —
 * verified or not — otherwise admins create duplicate rows (and the store
 * endpoint then rejects them with a 422 after the fact).
 */
class AdminOrganizationSearchTest extends TestCase
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

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function fakeDirectory(array $organizations): void
    {
        config(['services.charity_navigator.app_id' => null]);
        config(['services.charity_navigator.app_key' => null]);

        Http::fake([
            'projects.propublica.org/*' => Http::response(['organizations' => $organizations], 200),
        ]);
    }

    public function test_admin_search_hides_registered_orgs_by_ein_and_by_name(): void
    {
        // Verified registry row, matched by EIN.
        Organization::create([
            'name' => 'American National Red Cross',
            'ein' => '530196605',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        // Unverified registry row, matched by normalized name only (no EIN).
        Organization::create([
            'name' => 'Local Community Fund',
            'payout_methods' => ['paypal'],
        ]);

        $this->fakeDirectory([
            ['name' => 'AMERICAN  NATIONAL RED CROSS', 'ein' => 530196605],
            ['name' => 'local   community fund', 'ein' => 999999991],
            ['name' => 'Brand New Charity', 'ein' => 999999999],
        ]);

        $data = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations/search?q=fund')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertCount(1, $data, 'only orgs absent from the registry may be suggested');
        $this->assertSame('Brand New Charity', $data[0]['name']);
        $this->assertSame('999999999', $data[0]['ein']);
        $this->assertSame('propublica', $data[0]['source']);
    }

    public function test_admin_search_hides_verified_local_orgs_too(): void
    {
        // An org returned as a local registry hit in the public flow must also
        // be hidden here — the modal is for *new* orgs only.
        Organization::create([
            'name' => 'Verified Local Org',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->fakeDirectory([
            ['name' => 'Verified Local Org', 'ein' => 111111111],
            ['name' => 'Another Org', 'ein' => 222222222],
        ]);

        $data = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations/search?q=org')
            ->json('data');

        $this->assertSame(['Another Org'], array_column($data, 'name'));
    }

    public function test_public_charity_search_still_returns_verified_local_orgs(): void
    {
        Organization::create([
            'name' => 'Public Registry Org',
            'ein' => '777777777',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->fakeDirectory([
            ['name' => 'Public Registry Org', 'ein' => 777777777],
            ['name' => 'External Only Org', 'ein' => 888888888],
        ]);

        $data = $this->getJson('/api/v1/charities/search?q=org')->json('data');

        $this->assertSame('Public Registry Org', $data[0]['name']);
        $this->assertSame('local', $data[0]['source']);
        // The external duplicate is skipped; unrelated external rows remain.
        $this->assertSame(['Public Registry Org', 'External Only Org'], array_column($data, 'name'));
    }

    public function test_admin_search_matches_eins_when_the_directory_drops_leading_zeros(): void
    {
        // Registry stores the canonical nine digits; ProPublica sends the same
        // charity as an integer (60726487), so a raw string compare misses it.
        Organization::create([
            'name' => 'Save the Children Federation',
            'ein' => '060726487',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->fakeDirectory([
            ['name' => 'Save The Children Federation Inc', 'ein' => 60726487],
            ['name' => 'Another Org', 'ein' => 222222222],
        ]);

        $data = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations/search?q=children')
            ->json('data');

        $this->assertSame(['Another Org'], array_column($data, 'name'));
    }

    public function test_short_admin_query_returns_empty_without_calling_the_directory(): void
    {
        Http::fake();

        $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations/search?q=x')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_directory_search_requires_admin(): void
    {
        Role::firstOrCreate(['name' => 'Editor']);
        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        $this->withHeaders($this->headersFor($editor))
            ->getJson('/api/v1/admin/organizations/search?q=red%20cross')
            ->assertForbidden();

        $this->getJson('/api/v1/admin/organizations/search?q=red%20cross')->assertForbidden();
    }
}
