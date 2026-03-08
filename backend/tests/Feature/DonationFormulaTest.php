<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_multiple_formulas_on_single_article()
    {
        $user = User::factory()->create();
        $article = Article::create([
            'user_id' => $user->id,
            'title' => 'Test Article',
            'slug' => 'test-article',
            'sections' => json_encode([['title' => 'Section 1', 'content' => 'Content 1']]),
        ]);

        $formula1 = [
            ['organization' => 'Org 1', 'percentage' => 60],
            ['organization' => 'Org 2', 'percentage' => 40],
        ];

        $formula2 = [
            ['organization' => 'Org 3', 'percentage' => 50],
            ['organization' => 'Org 4', 'percentage' => 50],
        ];

        $response1 = $this->actingAs($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'title' => 'Formula 1',
            'formula' => $formula1,
        ]);

        $response1->assertStatus(201);

        $response2 = $this->actingAs($user)->postJson('/api/v1/donation-formulas', [
            'article_slug' => $article->slug,
            'title' => 'Formula 2',
            'formula' => $formula2,
        ]);

        $response2->assertStatus(201);

        $this->assertEquals(2, DonationFormula::where('article_id', $article->id)->where('user_id', $user->id)->count());
    }

    public function test_user_can_only_edit_their_own_formula()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $article = Article::create([
            'user_id' => $user1->id,
            'title' => 'Test Article',
            'slug' => 'test-article',
            'sections' => json_encode([['title' => 'Section 1', 'content' => 'Content 1']]),
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user1->id,
            'title' => 'User 1 Formula',
            'formula' => [['organization' => 'Org 1', 'percentage' => 100]],
        ]);

        $updatedFormula = [['organization' => 'Org 2', 'percentage' => 100]];

        // User 2 tries to edit User 1's formula
        $response = $this->actingAs($user2)->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
            'title' => 'User 2 Hacked it',
            'formula' => $updatedFormula,
        ]);

        $response->assertStatus(403);

        // User 1 edits their own formula
        $response = $this->actingAs($user1)->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
            'title' => 'User 1 Updated it',
            'formula' => $updatedFormula,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('User 1 Updated it', $formula->fresh()->title);
    }

    public function test_user_can_only_delete_their_own_formula()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $article = Article::create([
            'user_id' => $user1->id,
            'title' => 'Test Article',
            'slug' => 'test-article',
            'sections' => json_encode([['title' => 'Section 1', 'content' => 'Content 1']]),
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user1->id,
            'title' => 'User 1 Formula',
            'formula' => [['organization' => 'Org 1', 'percentage' => 100]],
        ]);

        // User 2 tries to delete User 1's formula
        $response = $this->actingAs($user2)->deleteJson("/api/v1/donation-formulas/{$formula->uuid}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('donation_formulas', ['id' => $formula->id]);

        // User 1 deletes their own formula
        $response = $this->actingAs($user1)->deleteJson("/api/v1/donation-formulas/{$formula->uuid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('donation_formulas', ['id' => $formula->id]);
    }
}
