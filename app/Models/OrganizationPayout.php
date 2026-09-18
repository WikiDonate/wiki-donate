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
        'organization_name',
        'organization_key',
        'amount',
        'currency',
        'type',
        'status',
        'paid_at',
        'actor_id',
        'method',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->paid_at)) {
                $model->paid_at = now();
            }
            if (empty($model->status)) {
                $model->status = 'paid';
            }
        });
    }

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
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
