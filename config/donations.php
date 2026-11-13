<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operational cost shown to donors (percent)
    |--------------------------------------------------------------------------
    |
    | Display-only platform/operational cost shown in the donate modal. It is
    | NOT the provider payout fee — that one is reported by PayPal per payout
    | (see payout_fee_estimate_percent below).
    |
    */
    'operational_cost_percent' => env('DONATION_OPERATIONAL_COST_PERCENT', 5),

    /*
    |--------------------------------------------------------------------------
    | Payout fee estimate (display only)
    |--------------------------------------------------------------------------
    |
    | The provider fee for a payout is NOT a fixed percentage — it depends on
    | the receiver's country/currency and is reported by PayPal after the
    | transfer (`payout_item_fee`, with fees_payer=RECEIVER). The value below
    | is only used to show an "estimated fee" in the admin Pay modal before
    | sending; the recorded fee/net always come from the provider.
    |
    */
    'payout_fee_estimate_percent' => env('PAYOUT_FEE_ESTIMATE_PERCENT', 2),

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
