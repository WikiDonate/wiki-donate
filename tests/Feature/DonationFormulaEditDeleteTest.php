<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DonationFormulaEditDeleteTest extends TestCase
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

    public function test_owner_can_delete_formula_with_donations_and_public_list_hides_it()
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'My Formula',
            'formula' => [['organization' => 'Org', 'percentage' => 100]],
        ]);

        Donation::create([
            'user_id' => null,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 10,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->actingEditor($owner)->deleteJson("/api/v1/donation-formulas/{$formula->uuid}");
        $response->assertOk();

        $this->assertSoftDeleted('donation_formulas', ['id' => $formula->id]);

        // Deleted formula no longer appears in the public list.
        $list = $this->getJson("/api/v1/donation-formulas/article/{$article->slug}");
        $list->assertOk();
        $this->assertNotContains($formula->uuid, collect($list->json('data'))->pluck('uuid')->all());
    }

    public function test_update_after_completed_donation_sets_edited_flags()
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'My Formula',
            'formula' => [['organization' => 'Org', 'percentage' => 100]],
        ]);

        Donation::create([
            'user_id' => null,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 10,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $update = $this->actingEditor($owner)->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
            'name' => 'My Formula',
            'formula' => [['organization' => 'New Org', 'percentage' => 100]],
        ]);
        $update->assertOk();

        $formula->refresh();
        $this->assertTrue((bool) $formula->is_edited);
        $this->assertNotNull($formula->edited_at);
        $this->assertSame('New Org', $formula->formula[0]['organization']);
    }

    public function test_update_without_completed_donation_does_not_set_edited_flags()
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'My Formula',
            'formula' => [['organization' => 'Org', 'percentage' => 100]],
        ]);

        $update = $this->actingEditor($owner)->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
            'name' => 'My Formula',
            'formula' => [['organization' => 'New Org', 'percentage' => 100]],
        ]);
        $update->assertOk();

        $formula->refresh();
        $this->assertFalse((bool) $formula->is_edited);
        $this->assertNull($formula->edited_at);
    }

    public function test_formula_list_exposes_completion_count_and_total_for_sorting()
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);

        $popular = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'Popular',
            'formula' => [['organization' => 'Org A', 'percentage' => 100]],
        ]);
        $quiet = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'Quiet',
            'formula' => [['organization' => 'Org B', 'percentage' => 100]],
        ]);

        // Two completed + one pending on the popular formula: only completed
        // rows must be counted/summed, or the "most popular" sort lies.
        foreach ([['completed', 10], ['completed', 15], ['pending', 99]] as [$status, $amount]) {
            Donation::create([
                'user_id' => null,
                'donation_formula_id' => $popular->id,
                'donor_name' => 'Donor',
                'donor_email' => 'donor@example.com',
                'amount' => $amount,
                'currency' => 'USD',
                'status' => $status,
            ]);
        }

        Donation::create([
            'user_id' => null,
            'donation_formula_id' => $quiet->id,
            'donor_name' => 'Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->getJson("/api/v1/donation-formulas/article/{$article->slug}");
        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy('name');

        $this->assertSame(2, $rows['Popular']['donations_count']);
        $this->assertSame(25.0, (float) $rows['Popular']['total_donated']);
        $this->assertSame(1, $rows['Quiet']['donations_count']);
        $this->assertSame(5.0, (float) $rows['Quiet']['total_donated']);
    }

    public function test_name_is_rejected_when_a_soft_deleted_formula_still_holds_it()
    {
        $owner = User::factory()->create();
        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'name' => 'Reused Name',
            'formula' => [['organization' => 'Org', 'percentage' => 100]],
        ]);

        // Soft-delete: the row stays in the table, so the DB unique index on
        // (article_id, user_id, name) still reserves the name.
        $this->actingEditor($owner)
            ->deleteJson("/api/v1/donation-formulas/{$formula->uuid}")
            ->assertOk();
        $this->assertSoftDeleted('donation_formulas', ['id' => $formula->id]);

        // Recreating the same name must be a clean 422, not a 1062 duplicate
        // key crash.
        $response = $this->actingEditor($owner)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'name' => 'Reused Name',
            'formula' => [['organization' => 'Org', 'percentage' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'A formula with this name already exists for this article.',
            $response->json('message'),
        );
    }
}
