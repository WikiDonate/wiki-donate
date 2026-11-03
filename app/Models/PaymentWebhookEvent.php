<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One inbound payment webhook delivery (PayPal or Stripe).
 *
 * The unique (provider, event_id) pair is the idempotency lock — it replaces
 * the old file-cache check that a deploy's `cache:clear` used to wipe.
 *
 * Every write helper here is non-throwing on purpose (same rule as
 * TransactionLog::record()): recording a delivery must never be the reason a
 * payment fails to settle.
 */
class PaymentWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    /**
     * Headers worth keeping for forensics. Anything else is dropped so the
     * table can never become a store of unrelated cookies/auth headers.
     */
    private const SAFE_HEADERS = [
        'content-type',
        'user-agent',
        'paypal-transmission-id',
        'paypal-transmission-time',
        'paypal-cert-url',
        'paypal-auth-algo',
        'paypal-request-id',
        'correlation-id',
        'stripe-signature',
    ];

    /**
     * Signature material is reduced to a short hash prefix: enough to
     * correlate a delivery, not enough to replay it.
     */
    private const HASHED_HEADERS = [
        'paypal-transmission-sig',
        'stripe-signature',
    ];

    protected $fillable = [
        'uuid',
        'provider',
        'event_id',
        'event_type',
        'status',
        'http_status',
        'signature_valid',
        'payload',
        'headers',
        'related_type',
        'related_id',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'http_status' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Claim this delivery, creating the row if it is the first arrival.
     *
     * Returns the existing row for a duplicate. A previously FAILED delivery
     * is re-opened so a gateway retry can succeed — only `processed` and
     * `ignored` are treated as final.
     *
     * Returns null if the log itself is unavailable; callers must continue.
     */
    public static function begin(
        string $provider,
        array $payload,
        array $headers,
        string $rawBody = '',
    ): ?self {
        try {
            $eventId = self::eventIdFor($payload, $rawBody);

            $existing = static::query()
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->first();

            if ($existing !== null) {
                if ($existing->status === self::STATUS_FAILED) {
                    $existing->forceFill([
                        'status' => self::STATUS_RECEIVED,
                        'http_status' => null,
                        'error_message' => null,
                        'processed_at' => null,
                        'received_at' => now(),
                    ])->save();
                }

                return $existing;
            }

            return static::create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => self::eventTypeFor($provider, $payload),
                'status' => self::STATUS_RECEIVED,
                'payload' => $payload ?: null,
                'headers' => self::safeHeaders($headers),
                'received_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A concurrent delivery may have won the unique-key race.
            try {
                return static::query()
                    ->where('provider', $provider)
                    ->where('event_id', self::eventIdFor($payload, $rawBody))
                    ->first();
            } catch (\Throwable) {
                report($e);

                return null;
            }
        }
    }

    /** A delivery that already reached a final state must not be re-applied. */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSED, self::STATUS_IGNORED], true);
    }

    public function markSignature(?bool $valid): void
    {
        $this->persist(['signature_valid' => $valid]);
    }

    public function markProcessed(
        int $httpStatus = 200,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): void {
        $this->persist([
            'status' => self::STATUS_PROCESSED,
            'http_status' => $httpStatus,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'error_message' => null,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $message, int $httpStatus = 500): void
    {
        $this->persist([
            'status' => self::STATUS_FAILED,
            'http_status' => $httpStatus,
            'error_message' => self::sanitize($message),
            'processed_at' => null,
        ]);
    }

    /**
     * Deliberately not processed and not a failure — e.g. Stripe arriving
     * while its webhook secret is unconfigured (it answers 503 and retries).
     */
    public function markIgnored(string $reason, int $httpStatus = 200): void
    {
        $this->persist([
            'status' => self::STATUS_IGNORED,
            'http_status' => $httpStatus,
            'error_message' => self::sanitize($reason),
            'processed_at' => now(),
        ]);
    }

    private function persist(array $attributes): void
    {
        try {
            $this->forceFill($attributes)->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Gateway event id, or a stable payload hash when the gateway sends none
     * (an unparseable body still dedupes by content).
     */
    private static function eventIdFor(array $payload, string $rawBody): string
    {
        $id = trim((string) ($payload['id'] ?? ''));

        if ($id === '') {
            $id = 'sha256:'.hash('sha256', $rawBody);
        }

        return mb_substr($id, 0, 100);
    }

    private static function eventTypeFor(string $provider, array $payload): ?string
    {
        $type = $provider === 'stripe'
            ? ($payload['type'] ?? null)
            : ($payload['event_type'] ?? null);

        if (! is_string($type) || $type === '') {
            return null;
        }

        // PayPal sends UPPERCASE already; Stripe is lowercase by convention.
        return mb_substr($provider === 'stripe' ? $type : strtoupper($type), 0, 100);
    }

    /**
     * Whitelist + hash the signature material. Never stores the raw bag.
     */
    private static function safeHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $key => $value) {
            $key = strtolower((string) $key);
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

            if (in_array($key, self::HASHED_HEADERS, true)) {
                $safe[$key] = 'sha256:'.substr(hash('sha256', $value), 0, 12);

                continue;
            }

            if (in_array($key, self::SAFE_HEADERS, true)) {
                $safe[$key] = mb_substr($value, 0, 500);
            }
        }

        return $safe;
    }

    /**
     * Strip anything secret-shaped and cap the length. Provider error bodies
     * have been known to echo credentials, and this text is readable by
     * admins, so keep it to a short, generic string.
     */
    private static function sanitize(string $message): string
    {
        $message = preg_replace(
            '/(client_secret|secret|access_token|password|api[_-]?key)\s*[=:]\s*\S+/i',
            '$1=[redacted]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
