<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only payouts ledger. No edits/deletes of amounts are allowed;
 * balances are computed live from this table plus current formula JSON.
 */
class OrganizationPayout extends Model
{
    protected $fillable = [
        'donation_formula_id',
        'organization_name',
        'amount',
        'currency',
        'type',
        'status',
        'paid_at',
        'actor',
        'note',
        'method',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function formula()
    {
        return $this->belongsTo(DonationFormula::class, 'donation_formula_id');
    }

    public function actorUser()
    {
        return $this->belongsTo(User::class, 'actor');
    }
}
