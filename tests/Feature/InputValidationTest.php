<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_rejects_short_password(): void
    {
        $this->postJson('/api/v1/user', [
            'username' => 'shortpw',
            'email' => 'shortpw@example.com',
            'password' => 'short',
            'confirmPassword' => 'short',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'shortpw@example.com']);
    }

    public function test_register_accepts_valid_password(): void
    {
        Role::firstOrCreate(['name' => 'Editor']);

        $this->postJson('/api/v1/user', [
            'username' => 'validuser',
            'email' => 'valid@example.com',
            'password' => 'LongEnough123',
            'confirmPassword' => 'LongEnough123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'valid@example.com']);
    }

    public function test_article_rejects_overlong_title(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin']));
        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/articles', [
                'title' => str_repeat('a', 300),
                'content' => '<p>hi</p>',
            ])
            ->assertUnprocessable();
    }

    public function test_stripe_checkout_rejects_out_of_range_amount(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $this->withHeaders($headers)
            ->postJson('/api/v1/stripe/checkout', ['amount' => 0.10])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/v1/stripe/checkout', ['amount' => 999999999])
            ->assertUnprocessable();
    }

    public function test_contact_rejects_oversized_details(): void
    {
        $this->postJson('/api/v1/contact', [
            'firstName' => 'A',
            'lastName' => 'B',
            'email' => 'a@b.c',
            'subject' => 'Hi',
            'details' => str_repeat('x', 6000),
        ])->assertUnprocessable();
    }

    private function formulaAuthHeaders(): array
    {
        $editor = User::factory()->create();
        $editor->assignRole(Role::firstOrCreate(['name' => 'Editor']));

        return ['Authorization' => 'Bearer '.$editor->createToken('test')->plainTextToken];
    }

    public function test_formula_rejects_malformed_ein(): void
    {
        $article = Article::create(['title' => 'T', 'slug' => 't-'.uniqid()]);

        $this->withHeaders($this->formulaAuthHeaders())
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'Bad EIN',
                'formula' => [['organization' => 'Bogus Org', 'ein' => 'ABC123', 'percentage' => 100]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', 'Row 1: EIN must be 9 digits (e.g. 53-0196605).');
    }

    public function test_formula_rejects_mismatched_id_and_ein(): void
    {
        $article = Article::create(['title' => 'T', 'slug' => 't-'.uniqid()]);
        $org = Organization::create([
            'name' => 'Real Org',
            'normalized_name' => 'real org',
            'ein' => '111111111',
        ]);

        $this->withHeaders($this->formulaAuthHeaders())
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'Mismatch',
                'formula' => [[
                    'organization' => 'Other Org',
                    'organization_id' => $org->id,
                    'ein' => '999999999',
                    'percentage' => 100,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', 'Row 1: EIN does not belong to the given organization.');
    }

    public function test_formula_accepts_matching_id_and_ein(): void
    {
        $article = Article::create(['title' => 'T', 'slug' => 't-'.uniqid()]);
        $org = Organization::create([
            'name' => 'Real Org',
            'normalized_name' => 'real org',
            'ein' => '111111111',
        ]);

        $this->withHeaders($this->formulaAuthHeaders())
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'Match',
                'formula' => [[
                    'organization' => 'Real Org',
                    'organization_id' => $org->id,
                    'ein' => '11-1111111',
                    'percentage' => 100,
                ]],
            ])
            ->assertCreated();
    }
}
