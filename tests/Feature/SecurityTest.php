<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
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
    // PASSWORD CHANGE — current password re-auth + token rotation
    // ---------------------------------------------------------------------

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Editor');

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/changePassword', [
                'currentPassword' => 'wrong-password',
                'newPassword' => 'NewPassword123',
                'confirmPassword' => 'NewPassword123',
            ])
            ->assertUnprocessable();

        // Password unchanged.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Editor');

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/changePassword', [
                'newPassword' => 'NewPassword123',
                'confirmPassword' => 'NewPassword123',
            ])
            ->assertUnprocessable();
    }

    public function test_change_password_rotates_other_tokens(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Editor');
        $other = $user->createToken('other-device')->plainTextToken;
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->postJson('/api/v1/changePassword', [
                'currentPassword' => 'password',
                'newPassword' => 'NewPassword123',
                'confirmPassword' => 'NewPassword123',
            ])
            ->assertCreated();

        // Other sessions logged out; current session survives.
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', explode('|', $other)[1])]);
        $this->withHeaders($headers)->getJson('/api/v1/user/get')->assertOk();
    }

    // ---------------------------------------------------------------------
    // CHECKOUT REDIRECTS — server-owned, never taken from the request
    // ---------------------------------------------------------------------

    /**
     * The client may not choose where the gateway sends the donor: the
     * checkout service builds success/cancel URLs from APP_URL. A supplied
     * `success_url` must be ignored (open-redirect / phishing regression).
     */
    public function test_checkout_ignores_client_supplied_redirect_urls(): void
    {
        $captured = [];

        $mock = Mockery::mock(PayPalClient::class);
        $mock->shouldReceive('getAccessToken')->andReturn('test-access-token');
        $mock->shouldReceive('createOrder')
            ->andReturnUsing(function ($amount, $currency, $returnUrl, $cancelUrl) use (&$captured) {
                $captured = compact('returnUrl', 'cancelUrl');

                return [
                    'id' => 'PAYPAL_REDIRECT_TEST',
                    'status' => 'CREATED',
                    'links' => [
                        ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=X'],
                    ],
                ];
            });

        $this->app->instance(PayPalClient::class, $mock);

        $this->postJson('/api/v1/payment/paypal/create-order', [
            'amount' => 10,
            'success_url' => 'https://evil.example/phish',
            'cancel_url' => 'https://evil.example/phish',
        ])->assertOk()->assertJsonMissing(['approval_url' => 'https://evil.example/phish']);

        $this->assertStringStartsWith((string) config('app.url'), $captured['returnUrl']);
        $this->assertStringStartsWith((string) config('app.url'), $captured['cancelUrl']);
        $this->assertStringNotContainsString('evil.example', $captured['returnUrl']);
        $this->assertStringNotContainsString('evil.example', $captured['cancelUrl']);
    }

    // ---------------------------------------------------------------------
    // REMOVED LEGACY ROUTES — must not linger as bypasses
    // ---------------------------------------------------------------------

    public function test_legacy_per_gateway_checkout_routes_no_longer_exist(): void
    {
        foreach ([
            '/api/v1/paypal/create-order',
            '/api/v1/paypal/capture-order',
            '/api/v1/stripe/checkout',
            '/api/v1/stripe/webhook',
        ] as $path) {
            $this->postJson($path, [])->assertNotFound();
        }
    }

    // ---------------------------------------------------------------------
    // AUTHENTICATION — protected routes must reject unauthenticated callers
    // ---------------------------------------------------------------------

    public function test_admin_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    /**
     * Clients that omit the Accept header must get the same JSON 401 — not a
     * 500 from Laravel trying to redirect to a `login` route this API does not
     * have. The payment surface is public, so this is reachable by anyone.
     */
    public function test_unauthenticated_api_request_without_accept_header_is_json_401(): void
    {
        $this->get('/api/v1/admin/payment-methods')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->post('/api/v1/logout')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized');
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
        $this->postJson('/api/v1/payment/paypal/capture-order', [])
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
