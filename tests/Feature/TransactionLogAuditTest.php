<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionLogAuditTest extends TestCase
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

    public function test_admin_can_list_transaction_logs(): void
    {
        $organization = Organization::create([
            'name' => 'Logged Org',
            'normalized_name' => Organization::normalizeName('Logged Org'),
        ]);

        TransactionLog::record(
            'organization.created',
            $organization,
            after: $organization->only(['name', 'normalized_name']),
            actorId: $this->admin->id,
        );

        $response = $this->asAdmin()->getJson('/api/v1/admin/transaction-logs');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('organization.created', $response->json('data.data.0.event'));
        $this->assertEquals($this->admin->username, $response->json('data.data.0.actor'));
    }

    public function test_transaction_log_filters_by_event_and_category(): void
    {
        $organization = Organization::create([
            'name' => 'Logged Org',
            'normalized_name' => Organization::normalizeName('Logged Org'),
        ]);

        TransactionLog::record('organization.created', $organization, actorId: $this->admin->id);
        TransactionLog::record('payout.created', $organization, actorId: $this->admin->id);

        $response = $this->asAdmin()->getJson('/api/v1/admin/transaction-logs?event=payout.created');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('payout.created', $response->json('data.data.0.event'));

        $response = $this->asAdmin()->getJson('/api/v1/admin/transaction-logs?category=organization');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_payout_creates_transaction_log_with_destination(): void
    {
        $article = Article::create(['title' => 'T', 'slug' => 't-'.uniqid()]);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->admin->id,
            'name' => 'Log Formula',
            'formula' => [
                ['organization' => 'Log Charity', 'organization_id' => null, 'percentage' => 100],
            ],
        ]);

        $organization = Organization::create([
            'name' => 'Log Charity',
            'normalized_name' => Organization::normalizeName('Log Charity'),
            'paypal_email' => 'finance@log.example',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $formula->update([
            'formula' => [
                ['organization' => 'Log Charity', 'organization_id' => $organization->id, 'percentage' => 100],
            ],
        ]);

        Donation::create([
            'uuid' => (string) Str::uuid(),
            'donation_formula_id' => $formula->id,
            'amount' => 100,
            'currency' => 'usd',
            'status' => 'completed',
        ]);

        $this->asAdmin()->postJson('/api/v1/admin/payouts', [
            'donation_formula_id' => $formula->id,
            'organization_name' => 'Log Charity',
            'amount' => 50,
            'currency' => 'usd',
        ])->assertCreated();

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'payout.created',
            'subject_type' => (new OrganizationPayout)->getMorphClass(),
            'actor_id' => $this->admin->id,
        ]);

        $log = TransactionLog::where('event', 'payout.created')->first();
        $this->assertEquals($organization->paypal_email, $log->after['destination_paypal_email']);
        $this->assertEquals($organization->id, $log->after['destination_organization_id']);
    }

    public function test_non_admin_cannot_access_transaction_logs(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        $token = $editor->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/transaction-logs')
            ->assertStatus(403);
    }
}
