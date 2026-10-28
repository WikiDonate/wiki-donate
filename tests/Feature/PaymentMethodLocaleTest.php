<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the i18n half of the pluggable payment-method work.
 *
 * The donor picker, the payment-result page and the admin filters/badges are
 * all data-driven from /v1/payment-methods, so the *generic* copy that renders
 * the method name at runtime must exist in every locale and must keep its
 * `{method}` interpolation token intact. A translation that localises the
 * token body (e.g. `{method}` -> `{método}`) silently renders a literal
 * placeholder — exactly the class of bug the backfill's masking step prevents.
 */
class PaymentMethodLocaleTest extends TestCase
{
    private const LOCALES_DIR = __DIR__.'/../../resources/js/i18n/locales';

    /** Keys added by the payment-method i18n subtask. */
    private const REQUIRED_KEYS = [
        'payment.methodLabel',
        'payment.unverifiedTitle',
        'payment.unverifiedMsg',
        'donate.paymentMethod',
        'donate.selectPaymentMethod',
        'donate.noPaymentMethods',
        'donate.methodNotConfigured',
        'donate.methodError',
        'donate.failedToLoadMethod',
        'admin.paymentMethod',
        'admin.paymentMethodLabel',
        'admin.allPaymentMethods',
    ];

    /** Keys whose value MUST keep the runtime `{method}` token. */
    private const METHOD_TOKEN_KEYS = [
        'payment.unverifiedTitle',
        'donate.methodNotConfigured',
        'donate.methodError',
        'donate.failedToLoadMethod',
    ];

    public function test_english_source_carries_every_generic_payment_key(): void
    {
        $en = $this->flat($this->load('en'));

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $en, "en.json is missing {$key}");
            $this->assertNotSame('', trim((string) $en[$key]), "en.json has an empty {$key}");
        }

        foreach (self::METHOD_TOKEN_KEYS as $key) {
            $this->assertStringContainsString('{method}', (string) $en[$key], "en.json {$key} lost its {method} token");
        }
    }

    public function test_every_locale_matches_the_english_key_set(): void
    {
        $en = $this->flat($this->load('en'));

        foreach ($this->localeFiles() as $code) {
            $flat = $this->flat($this->load($code));
            $missing = array_diff(array_keys($en), array_keys($flat));
            $extra = array_diff(array_keys($flat), array_keys($en));

            $this->assertSame([], array_values($missing), "{$code}.json is missing keys: ".implode(', ', $missing));
            $this->assertSame([], array_values($extra), "{$code}.json has untracked keys: ".implode(', ', $extra));
        }
    }

    public function test_every_locale_translates_the_generic_keys_without_breaking_tokens(): void
    {
        foreach ($this->localeFiles() as $code) {
            $flat = $this->flat($this->load($code));

            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey($key, $flat, "{$code}.json is missing {$key}");
                $this->assertNotSame('', trim((string) $flat[$key]), "{$code}.json has an empty {$key}");
            }

            foreach (self::METHOD_TOKEN_KEYS as $key) {
                $this->assertStringContainsString(
                    '{method}',
                    (string) $flat[$key],
                    "{$code}.json {$key} lost its {method} token (translation mangled the placeholder)"
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $code): array
    {
        $path = self::LOCALES_DIR."/{$code}.json";
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "{$code}.json is not valid JSON");

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function localeFiles(): array
    {
        $files = glob(self::LOCALES_DIR.'/*.json') ?: [];

        return array_values(array_filter(
            array_map(fn (string $path) => basename($path, '.json'), $files),
            fn (string $code) => $code !== 'en'
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flat(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $out += $this->flat($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
