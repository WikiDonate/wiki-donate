<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ein',
        'city',
        'state',
        'paypal_email',
        'payout_status',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    /**
     * Whether this org can receive a PayPal payout right now: a confirmed
     * receiving email plus an explicit verified status.
     */
    public function isPayoutReady(): bool
    {
        return $this->payout_status === 'verified' && filled($this->paypal_email);
    }
}
