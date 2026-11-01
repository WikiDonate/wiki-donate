<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only payout ledger.
 *
 * Payout amounts are never edited or deleted; the live balance for an
 * organization allocation is always computed as owed minus the sum of
 * payouts recorded here.
 */
class OrganizationPayout extends Model
{
    protected $fillable = [
        'uuid',
        'donation_formula_id',
        'organization_id',
        'organization_name',
        'organization_key',
        'destination_paypal_email',
        'amount',
        'fee_percent',
        'fee_amount',
        'net_amount',
        'currency',
        'type',
        'status',
        'paid_at',
        'submitted_at',
        'attempt_count',
        'reversed_at',
        'payout_batch_id',
        'payout_item_id',
        'provider_status',
        'failure_reason',
        'actor_id',
        'payment_method',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_percent' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    /**
     * Statuses that consume the live balance. A `paid` row that was later
     * reversed does NOT consume it any more (see isReversed()).
     *
     * @var array<int, string>
     */
    public const ON_BALANCE_STATUSES = ['paid', 'pending'];

    /**
     * Statuses that are terminal for reconciliation (the webhook handler and
     * syncPending() will not flip them again, except for reversals).
     *
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = ['paid', 'failed', 'reversed'];

    /**
     * A paid row PayPal later reported as returned/canceled/unclaimed: the
     * funds came back, so the balance must be re-opened.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Whether this row currently consumes the organization's live balance.
     * Mirrors the WHERE clause used by the balance aggregate so the rule
     * lives in exactly one place.
     */
    public function consumesBalance(): bool
    {
        if (! in_array($this->status, self::ON_BALANCE_STATUSES, true)) {
            return false;
        }

        return ! $this->isReversed();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->status)) {
                $model->status = 'pending';
            }
        });
    }

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorUser()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Normalize an organization name into a stable comparison key.
     */
    public static function makeKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
}
