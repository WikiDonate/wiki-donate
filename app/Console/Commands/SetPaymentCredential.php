<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Console\Command;

/**
 * Write a single gateway credential into the payment_methods registry.
 *
 * `credentials` is cast `encrypted:array` and is not fillable, so it must be
 * written through Eloquent with forceFill() — a raw SQL UPDATE stores
 * plaintext, decryption then fails silently, and the value reads back as
 * missing. This command is the supported way to set a key (e.g. a PayPal
 * webhook ID) without a tinker session.
 */
class SetPaymentCredential extends Command
{
    /**
     * Keys the providers actually read. Rejecting anything else turns a typo
     * (`webhookid`) into an error instead of a credential the app ignores.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_KEYS = [
        PaymentMethod::CODE_PAYPAL => ['client_id', 'client_secret', 'webhook_id'],
        PaymentMethod::CODE_STRIPE => ['secret_key', 'publishable_key', 'webhook_secret'],
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:set-credential
        {code : Payment method code (paypal, stripe)}
        {key : Credential key, e.g. webhook_id}
        {value? : Credential value; omit to be prompted (or piped via stdin)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store an encrypted gateway credential in the payment_methods registry';

    /**
     * Execute the console command.
     */
    public function handle(PaymentCredentials $credentials): int
    {
        $code = strtolower(trim((string) $this->argument('code')));
        $key = strtolower(trim((string) $this->argument('key')));

        $method = PaymentMethod::query()->where('code', $code)->first();

        if (! $method) {
            $this->error("Unknown payment method '{$code}'.");

            return self::FAILURE;
        }

        $allowed = self::ALLOWED_KEYS[$code] ?? [];

        if ($allowed === []) {
            $this->error("No credential keys are defined for '{$code}'.");
            $this->line('Known methods: '.implode(', ', array_keys(self::ALLOWED_KEYS)));

            return self::FAILURE;
        }

        if (! in_array($key, $allowed, true)) {
            $this->error("Unknown credential key '{$key}' for {$code}.");
            $this->line('Allowed keys: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        $value = $this->argument('value');

        if ($value === null) {
            // Interactive shell: hide the input. Piped input keeps the value
            // out of shell history (`printf %s "$ID" | php artisan …`).
            $value = $this->input->isInteractive()
                ? $this->secret("Value for {$code}.{$key}")
                : trim((string) stream_get_contents(STDIN));
        }

        $value = trim((string) $value);

        if ($value === '') {
            $this->error('The credential value is empty; nothing was written.');

            return self::FAILURE;
        }

        // Merge into the existing set: assigning a bare array would wipe the
        // other keys (client_secret would vanish when setting webhook_id).
        $current = $method->credentials;
        $current = is_array($current) ? $current : [];

        $replacing = array_key_exists($key, $current) && $current[$key] !== $value;

        $current[$key] = $value;

        // credentials is intentionally not fillable and the cast encrypts on
        // save; forceFill bypasses mass assignment without disabling the cast.
        $method->forceFill(['credentials' => $current])->save();

        // PaymentCredentials is a per-request singleton; drop the cache so a
        // long-running worker cannot keep serving the previous value.
        $credentials->flush();

        $this->info(($replacing ? 'Updated ' : 'Stored ')."{$code}.{$key} (".strlen($value).' chars).');

        if ($code === PaymentMethod::CODE_PAYPAL && $key === 'webhook_id') {
            $mode = $method->mode() ?? 'sandbox';

            $this->line("PayPal mode is <comment>{$mode}</comment>: the webhook ID must come from the {$mode} app.");
        }

        return self::SUCCESS;
    }
}
