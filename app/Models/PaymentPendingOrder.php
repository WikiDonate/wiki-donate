<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * In-flight checkout payload for any payment method.
 *
 * Renamed from PayPalPendingOrder (table paypal_pending_orders) in phase 3 of
 * the pluggable payment-method refactor: `payment_method` names the gateway and
 * `gateway_reference` holds the method-specific id (a PayPal order id, a Stripe
 * session id, ...).
 *
 * The row exists so the donor's formula/details survive the gateway redirect;
 * it is deleted once the donation is captured (or cleaned up after its TTL).
 */
class PaymentPendingOrder extends Model
{
    protected $table = 'payment_pending_orders';

    protected $fillable = [
        'payment_method',
        'gateway_reference',
        'user_id',
        'donation_formula_id',
        'donor_email',
        'donor_name',
        'amount',
        'currency',
        'details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
    }
}
