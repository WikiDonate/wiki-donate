<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Editor']);
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ---------------------------------------------------------------------
    // AUTHENTICATION — protected routes must reject unauthenticated callers
    // ---------------------------------------------------------------------

    public function test_admin_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    public function test_protected_user_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/changePassword', [])->assertStatus(401);
        $this->postJson('/api/v1/logout', [])->assertStatus(401);
        $this->getJson('/api/v1/user/get')->assertStatus(401);
        $this->putJson('/api/v1/user/update', [])->assertStatus(401);
        $this->getJson('/api/v1/user/notifications')->assertStatus(401);
        $this->postJson('/api/v1/stripe/card', [])->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // AUTHORIZATION — role middleware must enforce Admin / Editor
    // ---------------------------------------------------------------------

    public function test_admin_route_forbids_editor_role(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        $this->withHeaders($this->authHeader($editor))
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_admin_route_forbids_regular_user(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_admin_route_allows_admin_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->withHeaders($this->authHeader($admin))
            ->getJson('/api/v1/admin/articles')
            ->assertStatus(200);
    }

    public function test_editor_route_forbids_regular_user(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/articles/my')
            ->assertStatus(403);
    }

    public function test_editor_route_allows_editor_role(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        $this->withHeaders($this->authHeader($editor))
            ->getJson('/api/v1/articles/my')
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------------
    // PAYPAL CAPTURE — must remain callable without authentication (guest flow)
    // ---------------------------------------------------------------------

    public function test_paypal_capture_order_does_not_require_auth(): void
    {
        // No token: a 422 (validation) proves auth is NOT enforced here.
        $this->postJson('/api/v1/paypal/capture-order', [])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // RATE LIMITING — login must be throttled
    // ---------------------------------------------------------------------

    public function test_login_is_rate_limited(): void
    {
        $hitLimit = false;

        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/v1/login', [
                'username' => 'ghost',
                'password' => 'wrong',
            ]);

            if ($response->status() === 429) {
                $hitLimit = true;
                break;
            }
        }

        $this->assertTrue($hitLimit, 'Login endpoint was not rate limited');
    }

    // ---------------------------------------------------------------------
    // USER ENUMERATION — forgot-password must not reveal account existence
    // ---------------------------------------------------------------------

    public function test_forgot_password_does_not_enumerate_users(): void
    {
        $unknown = $this->postJson('/api/v1/forgotPassword', [
            'email' => 'does-not-exist@example.com',
        ]);

        $existing = $this->postJson('/api/v1/forgotPassword', [
            'email' => User::factory()->create()->email,
        ]);

        // Both must return the identical success response.
        $unknown->assertStatus(200)->assertExactJson($existing->json());
        $unknown->assertJson(['message' => 'A temporary password has been sent to your email.']);
    }

    // ---------------------------------------------------------------------
    // WEBHOOK SIGNATURE — PayPal webhook must reject unsigned payloads
    // ---------------------------------------------------------------------

    public function test_paypal_webhook_rejects_invalid_signature(): void
    {
        $this->postJson('/api/v1/webhooks/paypal', [])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // EMAIL VERIFICATION — link must not be forgeable
    // ---------------------------------------------------------------------

    public function test_email_verification_rejects_forged_hash(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        // Attacker only knows the id + email, and tries sha1(email).
        $forged = sha1('victim@example.com');

        $this->getJson("/api/v1/email/verify/{$user->id}/{$forged}")
            ->assertStatus(400);
    }

    public function test_email_verification_accepts_valid_hash(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        $hash = hash_hmac('sha256', $user->email, config('app.key'));

        $this->getJson("/api/v1/email/verify/{$user->id}/{$hash}")
            ->assertStatus(200);
    }
}
