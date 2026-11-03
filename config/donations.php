<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transaction cost (payment-rail fee) percentage
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the 5% operational/transaction cost shown to
    | donors and deducted from organization payouts. Payouts are enforced
    | server-side: the admin types a GROSS amount, the app deducts this
    | percentage and transfers the net to the organization.
    |
    | Change the rate here only — never hard-code it in a controller, service
    | or the SPA.
    |
    */
    'transaction_cost_percent' => env('DONATION_TRANSACTION_COST_PERCENT', 5),

    /*
    |--------------------------------------------------------------------------
    | Supported currency
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the currency the platform accepts. Donations
    | and organization payouts are USD only; any other code is rejected at the
    | API boundary instead of being silently coerced.
    |
    */
    'currency' => env('DONATION_CURRENCY', 'USD'),
];
