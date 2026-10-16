<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'Editor']);
    }

    private function actingEditor(User $user): self
    {
        $user->assignRole('Editor');

        return $this->actingAs($user, 'sanctum');
    }

    private function article(): Article
    {
        return Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);
    }

    public function test_creating_formula_upserts_organization_and_sets_organization_id(): void
    {
        $user = User::factory()->create();
        $article = $this->article();

        $response = $this->actingEditor($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Formula One',
            'formula' => [
                ['organization' => 'Save the Kittens', 'percentage' => 70],
                ['organization' => 'Ocean Cleanup', 'percentage' => 30],
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('organizations', [
            'name' => 'Save the Kittens',
            'normalized_name' => 'save the kittens',
        ]);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Ocean Cleanup',
            'normalized_name' => 'ocean cleanup',
        ]);

        $formula = DonationFormula::where('uuid', $response->json('data.uuid'))->first();
        $this->assertNotNull($formula);
        $this->assertCount(2, $formula->formula);
        $this->assertNotNull($formula->formula[0]['organization_id']);
        $this->assertNotNull($formula->formula[1]['organization_id']);
        $this->assertNotEquals(
            $formula->formula[0]['organization_id'],
            $formula->formula[1]['organization_id'],
        );
    }

    public function test_ein_match_takes_priority_over_name_match(): void
    {
        $user = User::factory()->create();
        $article = $this->article();

        $existing = Organization::create([
            'name' => 'Existing Org',
            'normalized_name' => Organization::normalizeName('Existing Org'),
            'ein' => '12-3456789',
        ]);

        $response = $this->actingEditor($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Formula EIN',
            'formula' => [
                ['organization' => 'Different Display Name', 'ein' => '12-3456789', 'percentage' => 100],
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseCount('organizations', 1);
        $formula = DonationFormula::where('uuid', $response->json('data.uuid'))->first();
        $this->assertEquals($existing->id, $formula->formula[0]['organization_id']);
    }

    public function test_name_match_falls_back_when_ein_missing(): void
    {
        $user = User::factory()->create();
        $article = $this->article();

        $existing = Organization::create([
            'name' => 'Wiki Foundation',
            'normalized_name' => Organization::normalizeName('Wiki Foundation'),
        ]);

        $response = $this->actingEditor($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Formula Name',
            'formula' => [
                ['organization' => '  Wiki  Foundation  ', 'percentage' => 100],
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseCount('organizations', 1);
        $formula = DonationFormula::where('uuid', $response->json('data.uuid'))->first();
        $this->assertEquals($existing->id, $formula->formula[0]['organization_id']);
    }

    public function test_existing_organization_id_is_respected_when_valid(): void
    {
        $user = User::factory()->create();
        $article = $this->article();

        $organization = Organization::create([
            'name' => 'Preloaded Org',
            'normalized_name' => Organization::normalizeName('Preloaded Org'),
        ]);

        $response = $this->actingEditor($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Formula With ID',
            'formula' => [
                ['organization' => 'Another Name', 'organization_id' => $organization->id, 'percentage' => 100],
            ],
        ]);

        $response->assertCreated();

        $formula = DonationFormula::where('uuid', $response->json('data.uuid'))->first();
        $this->assertEquals($organization->id, $formula->formula[0]['organization_id']);
    }

    public function test_invalid_organization_id_fails_validation(): void
    {
        $user = User::factory()->create();
        $article = $this->article();

        $response = $this->actingEditor($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Formula Bad ID',
            'formula' => [
                ['organization' => 'Some Org', 'organization_id' => 99999, 'percentage' => 100],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'success' => false,
            'message' => 'Validation error',
        ]);
        $this->assertContains(
            'The selected formula.0.organization_id is invalid.',
            $response->json('errors'),
        );
    }

    public function test_updating_formula_updates_organization_links(): void
    {
        $user = User::factory()->create();
        $article = $this->article();
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'name' => 'My Formula',
            'formula' => [['organization' => 'Old Org', 'percentage' => 100]],
        ]);

        $response = $this->actingEditor($user)->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
            'name' => 'My Formula',
            'formula' => [
                ['organization' => 'New Org', 'percentage' => 100],
            ],
        ]);

        $response->assertOk();

        $formula->refresh();
        $this->assertSame('New Org', $formula->formula[0]['organization']);
        $this->assertNotNull($formula->formula[0]['organization_id']);
        $this->assertDatabaseHas('organizations', [
            'name' => 'New Org',
            'normalized_name' => 'new org',
        ]);
    }
}
