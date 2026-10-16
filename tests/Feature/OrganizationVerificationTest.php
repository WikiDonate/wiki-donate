<?php

namespace Tests\Feature;

use App\Mail\OrganizationVerificationMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Editor']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function asAdmin(): self
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_admin_can_list_organizations(): void
    {
        Organization::create([
            'name' => 'Save the Kittens',
            'normalized_name' => Organization::normalizeName('Save the Kittens'),
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/organizations');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Save the Kittens', $response->json('data.data.0.name'));
    }

    public function test_admin_can_update_paypal_email(): void
    {
        $organization = Organization::create([
            'name' => 'Ocean Cleanup',
            'normalized_name' => Organization::normalizeName('Ocean Cleanup'),
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $response = $this->asAdmin()->putJson("/api/v1/admin/organizations/{$organization->id}", [
            'paypal_email' => 'finance@oceancleanup.example',
        ]);

        $response->assertOk();
        $organization->refresh();
        $this->assertEquals('finance@oceancleanup.example', $organization->paypal_email);
        $this->assertEquals('unverified', $organization->payout_status);
        $this->assertNull($organization->verified_at);

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'organization.verification_reset',
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_cannot_verify_without_paypal_email(): void
    {
        $organization = Organization::create([
            'name' => 'Missing PayPal',
            'normalized_name' => Organization::normalizeName('Missing PayPal'),
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/admin/organizations/{$organization->id}/verify");

        $response->assertStatus(422);
        $this->assertEquals('unverified', $organization->refresh()->payout_status);
    }

    public function test_admin_can_verify_organization_and_logs_event(): void
    {
        Mail::fake();

        $organization = Organization::create([
            'name' => 'Ready Org',
            'normalized_name' => Organization::normalizeName('Ready Org'),
            'paypal_email' => 'payments@readyorg.example',
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/admin/organizations/{$organization->id}/verify");

        $response->assertOk();
        $organization->refresh();
        $this->assertEquals('verified', $organization->payout_status);
        $this->assertNotNull($organization->verified_at);

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'organization.verified',
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'actor_id' => $this->admin->id,
        ]);

        Mail::assertQueued(OrganizationVerificationMail::class, function ($mail) use ($organization) {
            return $mail->organization->is($organization);
        });
    }

    public function test_admin_can_unverify_organization(): void
    {
        $organization = Organization::create([
            'name' => 'Verified Org',
            'normalized_name' => Organization::normalizeName('Verified Org'),
            'paypal_email' => 'a@b.c',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $response = $this->asAdmin()->postJson("/api/v1/admin/organizations/{$organization->id}/unverify");

        $response->assertOk();
        $organization->refresh();
        $this->assertEquals('unverified', $organization->payout_status);
        $this->assertNull($organization->verified_at);

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'organization.unverified',
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
        ]);
    }

    public function test_non_admin_cannot_access_organization_routes(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        $token = $editor->createToken('test')->plainTextToken;

        $organization = Organization::create([
            'name' => 'Protected Org',
            'normalized_name' => Organization::normalizeName('Protected Org'),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/organizations')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/organizations/{$organization->id}", ['paypal_email' => 'x@y.z'])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/organizations/{$organization->id}/verify")
            ->assertStatus(403);
    }

    public function test_organization_list_can_filter_by_status(): void
    {
        Organization::create([
            'name' => 'Verified One',
            'normalized_name' => Organization::normalizeName('Verified One'),
            'payout_status' => 'verified',
            'paypal_email' => 'a@b.c',
            'verified_at' => now(),
        ]);
        Organization::create([
            'name' => 'Unverified One',
            'normalized_name' => Organization::normalizeName('Unverified One'),
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/organizations?status=verified');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Verified One', $response->json('data.data.0.name'));
    }
}
