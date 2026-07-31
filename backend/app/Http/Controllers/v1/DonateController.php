<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Stripe\Stripe;

class DonateController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }
}
