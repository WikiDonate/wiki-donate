<?php

namespace Tests\Feature;

use App\Mail\OrganizationVerificationMail;
use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Org registry & payout directory flow:
 * - formula save/update upserts organizations (EIN-keyed, name fallback)
 *   and persists organization_id back into the formula rows
 * - admin organizations endpoints list / set PayPal email / verify
 * - audit rows are written for every org + transaction event
 */
class OrganizationRegistryTest extends TestCase
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

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function article(): Article
    {
        return Article::create([
            'slug' => 'org-registry-article-'.uniqid(),
            'title' => 'Org Registry Article',
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. Formula create/update upserts orgs and persists organization_id
    // ---------------------------------------------------------------------

    public function test_formula_create_upserts_orgs_and_persists_organization_id(): void
    {
        $article = $this->article();

        $response = $this->withHeaders($this->headersFor($this->editor))
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'My Formula',
                'formula' => [
                    ['organization' => 'American Red Cross', 'ein' => '530196605', 'percentage' => 60],
                    ['organization' => 'Local Free-Text Org', 'ein' => null, 'percentage' => 40],
                ],
            ]);

        $response->assertCreated();

        $rows = $response->json('data.formula');
        $this->assertCount(2, $rows);

        $redCross = Organization::where('ein', '530196605')->first();
        $this->assertNotNull($redCross);
        $this->assertEquals('American Red Cross', $redCross->name);

        // organization_id persisted back into the formula row
        $this->assertEquals($redCross->id, $rows[0]['organization_id']);

        // free-text row matched by name, nullable EIN
        $freeText = Organization::where('name', 'Local Free-Text Org')->first();
        $this->assertNotNull($freeText);
        $this->assertNull($freeText->ein);
        $this->assertEquals($freeText->id, $rows[1]['organization_id']);

        // audit rows written
        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.upserted']);
        $this->assertDatabaseHas('transaction_logs', ['event' => 'formula.created']);
    }

    public function test_formula_create_dedupes_by_ein(): void
    {
        $article = $this->article();

        $first = $this->withHeaders($this->headersFor($this->editor))
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'Formula One',
                'formula' => [
                    ['organization' => 'Red Cross', 'ein' => '530196605', 'percentage' => 100],
                ],
            ]);

        $sameEin = $this->withHeaders($this->headersFor($this->editor))
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $article->slug,
                'name' => 'Formula Two',
                'formula' => [
                    // Same EIN, different casing/name → must resolve to the SAME org row
                    ['organization' => 'american red cross', 'ein' => '53-0196605', 'percentage' => 100],
                ],
            ]);

        $first->assertCreated();
        $sameEin->assertCreated();

        $this->assertSame(1, Organization::where('ein', '530196605')->count());
        $this->assertEquals(
            $first->json('data.formula.0.organization_id'),
            $sameEin->json('data.formula.0.organization_id')
        );
    }

    public function test_formula_update_upserts_new_org_and_keeps_existing(): void
    {
        $article = $this->article();
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $this->editor->id,
            'name' => 'Existing Formula',
            'formula' => [
                ['organization' => 'Org A', 'ein' => null, 'percentage' => 50],
                ['organization' => 'Org B', 'ein' => null, 'percentage' => 50],
            ],
        ]);

        $response = $this->withHeaders($this->headersFor($this->editor))
            ->putJson("/api/v1/donation-formulas/{$formula->uuid}", [
                'name' => 'Existing Formula',
                'formula' => [
                    ['organization' => 'Org A', 'ein' => null, 'percentage' => 50],
                    ['organization' => 'Org C', 'ein' => null, 'percentage' => 50],
                ],
            ]);

        $response->assertOk();

        $orgA = Organization::where('name', 'Org A')->first();
        $orgC = Organization::where('name', 'Org C')->first();
        $this->assertNotNull($orgA);
        $this->assertNotNull($orgC);

        $rows = $response->json('data.formula');
        $this->assertEquals($orgA->id, $rows[0]['organization_id']);
        $this->assertEquals($orgC->id, $rows[1]['organization_id']);

        $this->assertDatabaseHas('transaction_logs', ['event' => 'formula.updated']);
    }

    // ---------------------------------------------------------------------
    // 2. Admin organizations endpoints
    // ---------------------------------------------------------------------

    public function test_admin_lists_organizations_with_filters(): void
    {
        Organization::create(['name' => 'Verified Org', 'ein' => '111111111', 'payout_status' => 'verified', 'verified_at' => now(), 'paypal_email' => 'a@b.test']);
        Organization::create(['name' => 'Pending Org', 'ein' => '222222222', 'payout_status' => 'unverified']);

        $all = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations');

        $all->assertOk();
        $this->assertCount(2, $all->json('data.data'));

        $verified = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/organizations?status=verified');

        $verified->assertOk();
        $this->assertCount(1, $verified->json('data.data'));
        $this->assertEquals('Verified Org', $verified->json('data.data.0.name'));
    }

    public function test_admin_sets_paypal_email_and_logs_change(): void
    {
        $org = Organization::create(['name' => 'Some Org', 'ein' => '333333333']);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}/paypal-email", [
                'paypal_email' => 'finance@someorg.test',
            ]);

        $response->assertOk();
        $this->assertSame('finance@someorg.test', $org->refresh()->paypal_email);

        $this->assertDatabaseHas('transaction_logs', [
            'event' => 'organization.paypal_email_changed',
        ]);

        $log = TransactionLog::where('event', 'organization.paypal_email_changed')->first();
        $this->assertEquals('Admin', $log->actor_role);
        $this->assertEquals(['paypal_email' => null], $log->before);
        $this->assertEquals(['paypal_email' => 'finance@someorg.test'], $log->after);
        $this->assertEquals($org->id, $log->subject_id);
    }

    public function test_changing_paypal_email_resets_verification(): void
    {
        $org = Organization::create([
            'name' => 'Reset Org',
            'ein' => '444444444',
            'paypal_email' => 'old@org.test',
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($this->admin))
            ->putJson("/api/v1/admin/organizations/{$org->id}/paypal-email", [
                'paypal_email' => 'new@org.test',
            ])
            ->assertOk();

        $org->refresh();
        $this->assertSame('unverified', $org->payout_status);
        $this->assertNull($org->verified_at);

        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.verification_reset']);
    }

    public function test_admin_verifies_org_and_sends_confirmation_mail(): void
    {
        Mail::fake();

        $org = Organization::create([
            'name' => 'Verify Me',
            'ein' => '555555555',
            'paypal_email' => 'verify@org.test',
            'payout_status' => 'unverified',
        ]);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->postJson("/api/v1/admin/organizations/{$org->id}/verify");

        $response->assertOk();
        $org->refresh();
        $this->assertSame('verified', $org->payout_status);
        $this->assertNotNull($org->verified_at);

        $this->assertDatabaseHas('transaction_logs', ['event' => 'organization.verified']);
        Mail::assertQueued(OrganizationVerificationMail::class);
    }

    public function test_admin_cannot_verify_without_paypal_email(): void
    {
        $org = Organization::create(['name' => 'No Email', 'ein' => '666666666']);

        $response = $this->withHeaders($this->headersFor($this->admin))
            ->postJson("/api/v1/admin/organizations/{$org->id}/verify");

        $response->assertStatus(422);
        $this->assertSame('unverified', $org->refresh()->payout_status);
        $this->assertDatabaseMissing('transaction_logs', ['event' => 'organization.verified']);
    }

    public function test_organizations_endpoints_require_admin_role(): void
    {
        $org = Organization::create(['name' => 'Denied', 'ein' => '777777777']);

        $this->withHeaders($this->headersFor($this->editor))
            ->getJson('/api/v1/admin/organizations')
            ->assertStatus(403);

        $this->withHeaders($this->headersFor($this->editor))
            ->putJson("/api/v1/admin/organizations/{$org->id}/paypal-email", ['paypal_email' => 'x@y.test'])
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // 3. Audit log browse/export
    // ---------------------------------------------------------------------

    public function test_transaction_log_index_filters_by_event(): void
    {
        // Org upserts only happen through the registry service / formula flow,
        // which is also what writes the audit row.
        $this->withHeaders($this->headersFor($this->editor))
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $this->article()->slug,
                'name' => 'Log Formula',
                'formula' => [
                    ['organization' => 'Logged Org', 'ein' => '888888888', 'percentage' => 100],
                ],
            ])
            ->assertCreated();

        $res = $this->withHeaders($this->headersFor($this->admin))
            ->getJson('/api/v1/admin/transaction-logs?event=organization.upserted');

        $res->assertOk();
        $this->assertCount(1, $res->json('data.data'));
        $this->assertEquals('organization.upserted', $res->json('data.data.0.event'));
    }

    public function test_transaction_log_export_returns_csv(): void
    {
        $this->withHeaders($this->headersFor($this->editor))
            ->postJson('/api/v1/donation-formulas/', [
                'article_slug' => $this->article()->slug,
                'name' => 'CSV Formula',
                'formula' => [
                    ['organization' => 'CSV Org', 'ein' => '999999999', 'percentage' => 100],
                ],
            ])
            ->assertCreated();

        $res = $this->withHeaders($this->headersFor($this->admin))
            ->get('/api/v1/admin/transaction-logs/export');

        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('organization.upserted', $csv);
    }
}
