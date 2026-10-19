<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Donation extends Model
{
    use HasFactory;

    public const METHOD_PAYPAL = 'paypal';

    public const METHOD_STRIPE = 'stripe';

    protected $fillable = [
        'uuid',
        'user_id',
        'donation_formula_id',
        'payment_method',
        'gateway_reference',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'paypal_order_id',
        'donor_name',
        'donor_email',
        'amount',
        'currency',
        'status',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });

        // Phase 1 mirror: generic payment_method/gateway_reference are kept in
        // sync with the still-authoritative legacy columns on every write, so
        // reads can migrate before phase 3 drops paypal_order_id /
        // stripe_session_id / stripe_payment_intent_id.
        static::saving(function ($model) {
            $model->mirrorPaymentIdentifiers();
        });
    }

    /**
     * Keep legacy ids and the generic pair consistent on write.
     *
     * Legacy columns win when present (existing flows still set them);
     * otherwise the generic pair is decomposed back into the legacy columns so
     * pre-refactor code paths keep working during the transition.
     */
    protected function mirrorPaymentIdentifiers(): void
    {
        if ($this->paypal_order_id) {
            $this->payment_method = self::METHOD_PAYPAL;
            $this->gateway_reference = $this->paypal_order_id;

            return;
        }

        if ($this->stripe_session_id || $this->stripe_payment_intent_id) {
            $this->payment_method = self::METHOD_STRIPE;
            $this->gateway_reference = $this->stripe_session_id ?: $this->stripe_payment_intent_id;

            return;
        }

        if ($this->payment_method === self::METHOD_PAYPAL && $this->gateway_reference) {
            $this->paypal_order_id = $this->gateway_reference;

            return;
        }

        if ($this->payment_method === self::METHOD_STRIPE && $this->gateway_reference) {
            $this->stripe_session_id = $this->gateway_reference;
        }
    }

    /**
     * Generic gateway reference for this donation, falling back to the legacy
     * columns for pre-refactor rows that were never backfilled.
     */
    public function gatewayReference(): ?string
    {
        return $this->gateway_reference
            ?: ($this->paypal_order_id ?: ($this->stripe_session_id ?: $this->stripe_payment_intent_id));
    }

    /**
     * Generic payment method code ('paypal', 'stripe', ...), inferred from the
     * legacy columns for rows written before the generic column existed.
     */
    public function paymentMethodCode(): ?string
    {
        if ($this->payment_method) {
            return $this->payment_method;
        }

        return $this->paypal_order_id ? self::METHOD_PAYPAL : ($this->stripe_session_id || $this->stripe_payment_intent_id ? self::METHOD_STRIPE : null);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
