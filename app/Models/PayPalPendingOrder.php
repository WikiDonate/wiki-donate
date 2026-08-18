<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayPalPendingOrder extends Model
{
    protected $table = 'paypal_pending_orders';

    protected $fillable = [
        'paypal_order_id',
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
