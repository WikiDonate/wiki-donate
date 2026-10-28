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
    }

    /**
     * Match a donation by its gateway identity — the generic pair that phase 3
     * migrated every webhook/capture lookup onto.
     */
    public function scopeGateway($query, string $method, string $reference)
    {
        return $query->where('payment_method', $method)
            ->where('gateway_reference', $reference);
    }

    /**
     * Generic gateway reference for this donation (PayPal order id, Stripe
     * session id, ...). Replaces the dropped per-gateway id columns.
     */
    public function gatewayReference(): ?string
    {
        return $this->gateway_reference;
    }

    /**
     * Generic payment method code ('paypal', 'stripe', ...).
     */
    public function paymentMethodCode(): ?string
    {
        return $this->payment_method;
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
