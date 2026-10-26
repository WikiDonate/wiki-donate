<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A registered payment method (PayPal, Stripe, bKash, ...).
 *
 * Rows are the single source of truth for which methods are offered to donors
 * (type income/both, active) and to admins for payouts (type payout/both).
 * Provider implementations are bound by `code`; see
 * App\Services\Payment\PaymentProviderRegistry.
 *
 * The `config` column holds NON-SECRET metadata only (mode, currencies,
 * webhook route/events, limits). Gateway credentials live in the encrypted
 * `credentials` column (APP_KEY) and are read through
 * App\Services\Payment\PaymentCredentials.
 */
class PaymentMethod extends Model
{
    public const TYPE_INCOME = 'income';

    public const TYPE_PAYOUT = 'payout';

    public const TYPE_BOTH = 'both';

    public const CODE_PAYPAL = 'paypal';

    public const CODE_STRIPE = 'stripe';

    protected $fillable = [
        'code',
        'label',
        'type',
        'active',
        'config',
        'sort_order',
    ];

    /**
     * `credentials` is intentionally not fillable and never serialised: it is
     * written through forceFill()/credential setters only, and must not leak
     * through model->toArray()/JSON responses.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected $casts = [
        'active' => 'boolean',
        'config' => 'array',
        'credentials' => 'encrypted:array',
        'sort_order' => 'integer',
    ];

    /** Only methods that should be visible to donors/admins. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Methods that can take money in (donor checkout). */
    public function scopeIncome(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_INCOME, self::TYPE_BOTH]);
    }

    /** Methods that can pay money out (admin payouts). */
    public function scopePayout(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_PAYOUT, self::TYPE_BOTH]);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function isPayoutCapable(): bool
    {
        return in_array($this->type, [self::TYPE_PAYOUT, self::TYPE_BOTH], true);
    }

    public function isIncomeCapable(): bool
    {
        return in_array($this->type, [self::TYPE_INCOME, self::TYPE_BOTH], true);
    }

    /**
     * Non-secret metadata helper. Never returns credentials.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function configValue(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Gateway mode for this method (e.g. sandbox|live).
     *
     * The registry row is the single source of truth: the mode is no longer
     * read from .env (PAYPAL_MODE was removed), so admins can switch a method
     * between sandbox and live without touching deployment secrets.
     */
    public function mode(): ?string
    {
        return $this->configValue('mode');
    }

    /**
     * A single credential from the encrypted `credentials` column.
     *
     * Prefer App\Services\Payment\PaymentCredentials in application code (it
     * caches per request); never log or serialise the return value.
     */
    public function credential(string $key, ?string $default = null): ?string
    {
        $value = data_get($this->credentials, $key);

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    /** Route path (relative to /webhooks/{code}) for this method's webhook. */
    public function webhookRoute(): ?string
    {
        return $this->configValue('webhook.route');
    }
}
