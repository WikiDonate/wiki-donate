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
 * it is deleted once the donation is captured.
 *
 * A checkout that ends without a donation (declined, cancelled, expired) is
 * NOT deleted: it is closed with a terminal `status` so the read-only status
 * endpoint can answer the donor honestly instead of 404 (which the return page
 * used to render as a false success). Terminal rows are pruned by age.
 */
class PaymentPendingOrder extends Model
{
    /** Still waiting for the donor / gateway. */
    public const STATUS_PENDING = 'pending';

    /** Gateway declined the capture. */
    public const STATUS_FAILED = 'failed';

    /** Donor cancelled, or the order was voided. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Gateway let the checkout expire. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Statuses that can no longer change without a new checkout.
     *
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED];
    }

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
        'status',
        'status_reason',
        'details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** True while the checkout can still be captured. */
    public function isOpen(): bool
    {
        return ($this->status ?? self::STATUS_PENDING) === self::STATUS_PENDING;
    }

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
    }
}
