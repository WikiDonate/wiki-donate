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
 * The `config` column holds NON-SECRET metadata only. Credentials live in
 * .env / config('services.*') and are referenced by env-var NAME.
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

    protected $casts = [
        'active' => 'boolean',
        'config' => 'array',
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

    /** Name of the env var holding this method's webhook secret, if any. */
    public function webhookSecretRef(): ?string
    {
        return $this->configValue('webhook.secret_ref');
    }

    /** Route path (relative to /webhooks/{code}) for this method's webhook. */
    public function webhookRoute(): ?string
    {
        return $this->configValue('webhook.route');
    }
}
