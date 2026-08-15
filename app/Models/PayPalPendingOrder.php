<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayPalPendingOrder extends Model
{
    protected $table = 'paypal_pending_orders';

    protected $fillable = [
        'paypal_order_id',
        'user_id',
        'donor_email',
        'donor_name',
        'amount',
        'currency',
        'formula',
        'details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'formula' => 'array',
    ];
}
