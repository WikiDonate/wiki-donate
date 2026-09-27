<?php

namespace Tests\Feature;

use App\Mail\OrganizationEmailConfirmationMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationEmailConfirmationTest extends TestCase
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

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->admin->createToken('test')->plainTextToken];
    }

    public function test_setting_email_sends_confirmation_with_token(): void
    {
        Mail::fake();

        $org = Organization::create([
            'name' => 'Confirm Org',
            'normalized_name' => Organization::normalizeName('Confirm Org'),
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['paypal_email' => 'pay@confirm.example'])
            ->assertOk();

        $org->refresh();
        $this->assertNotNull($org->paypal_confirm_token);
        $this->assertNotNull($org->paypal_confirm_sent_at);
        $this->assertEquals('unverified', $org->payout_status);

        Mail::assertQueued(OrganizationEmailConfirmationMail::class, function ($mail) use ($org) {
            return $mail->organization->is($org)
                && str_contains($mail->confirmUrl, (string) $org->paypal_confirm_token);
        });
    }

    public function test_valid_token_verifies_and_clears_token(): void
    {
        Mail::fake();

        $org = Organization::create([
            'name' => 'Click Org',
            'normalized_name' => Organization::normalizeName('Click Org'),
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['paypal_email' => 'pay@click.example'])
            ->assertOk();

        $token = $org->refresh()->paypal_confirm_token;
        $this->assertNotNull($token);

        $this->postJson('/api/v1/organizations/confirm-email', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Click Org');

        $org->refresh();
        $this->assertEquals('verified', $org->payout_status);
        $this->assertNotNull($org->verified_at);
        $this->assertNull($org->paypal_confirm_token);
        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'organization.verified',
            'subject_id' => $org->id,
        ]);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->postJson('/api/v1/organizations/confirm-email', ['token' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_expired_token_is_rejected(): void
    {
        $org = Organization::create([
            'name' => 'Stale Org',
            'normalized_name' => Organization::normalizeName('Stale Org'),
            'paypal_email' => 'pay@stale.example',
            'paypal_confirm_token' => str_repeat('a', 48),
            'paypal_confirm_sent_at' => now()->subDays(8),
        ]);

        $this->postJson('/api/v1/organizations/confirm-email', ['token' => str_repeat('a', 48)])
            ->assertUnprocessable();

        $this->assertEquals('unverified', $org->refresh()->payout_status);
    }

    public function test_email_change_invalidates_previous_token(): void
    {
        Mail::fake();

        $org = Organization::create([
            'name' => 'Rotating Org',
            'normalized_name' => Organization::normalizeName('Rotating Org'),
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['paypal_email' => 'one@rot.example'])
            ->assertOk();
        $firstToken = $org->refresh()->paypal_confirm_token;

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/admin/organizations/{$org->id}", ['paypal_email' => 'two@rot.example'])
            ->assertOk();

        $this->assertNotEquals($firstToken, $org->refresh()->paypal_confirm_token);
        $this->postJson('/api/v1/organizations/confirm-email', ['token' => $firstToken])
            ->assertUnprocessable();
    }
}
