<?php

use App\Models\PaymentMethod;
use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move gateway credentials out of .env and into the registry.
     *
     * The column is encrypted at rest with APP_KEY (see the `credentials` cast
     * on PaymentMethod) and is never serialised to the API/SPA.
     *
     * The one-time backfill deliberately reads the *current .env file* instead
     * of config(): on the VPS a cached config from the previous release means
     * env()/config() may no longer expose these keys by the time the migration
     * runs. Reading the file makes the copy deterministic, so the credentials
     * survive the deploy that deletes them from the environment.
     *
     * @var array<string, array<string, string>>
     */
    private const ENV_MAP = [
        'paypal' => [
            'client_id' => 'PAYPAL_CLIENT_ID',
            'client_secret' => 'PAYPAL_SECRET',
            'webhook_id' => 'PAYPAL_WEBHOOK_ID',
        ],
        'stripe' => [
            'secret_key' => 'STRIPE_SECRET_KEY',
            'publishable_key' => 'STRIPE_PUBLISHABLE_KEY',
            'webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('payment_methods', 'credentials')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->text('credentials')->nullable()->after('config');
            });
        }

        $this->backfillFromEnvFile();
        $this->stripLegacySecretRefs();
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_methods', 'credentials')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropColumn('credentials');
            });
        }
    }

    /**
     * Remove the pre-credentials `webhook.secret_ref` pointer from existing
     * rows. Secrets now live in the encrypted `credentials` column, so the
     * env-var NAME is stale metadata that invites reintroducing env keys.
     */
    private function stripLegacySecretRefs(): void
    {
        PaymentMethod::query()->each(function (PaymentMethod $method) {
            $config = $method->config;

            if (! is_array($config) || ! isset($config['webhook']['secret_ref'])) {
                return;
            }

            unset($config['webhook']['secret_ref']);

            // config is not fillable/guarded metadata; forceFill bypasses mass
            // assignment so the cast still applies.
            $method->forceFill(['config' => $config])->save();
        });
    }

    /**
     * Copy any credentials still present in .env into the registry.
     *
     * Existing credentials are never overwritten: the migration is a one-time
     * import, not a sync (the DB is the source of truth from here on).
     */
    private function backfillFromEnvFile(): void
    {
        // Tests must never inherit a developer's real credentials, and the
        // in-memory database is disposable anyway.
        if (app()->runningUnitTests()) {
            return;
        }

        $path = base_path('.env');

        if (! is_file($path) || ! is_readable($path)) {
            return;
        }

        $env = Dotenv::parse((string) file_get_contents($path));

        foreach (self::ENV_MAP as $code => $map) {
            $credentials = [];

            foreach ($map as $key => $envKey) {
                $value = trim((string) ($env[$envKey] ?? ''));

                if ($value !== '') {
                    $credentials[$key] = $value;
                }
            }

            if ($credentials === []) {
                continue;
            }

            $method = PaymentMethod::query()->where('code', $code)->first();

            if (! $method || ! empty($method->credentials)) {
                continue;
            }

            $method->forceFill(['credentials' => $credentials])->save();
        }
    }
};
