<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\DB;

/**
 * Phase-1 backfill: derive the generic `payment_method` + `gateway_reference`
 * pair on `donations` from the still-authoritative legacy id columns.
 *
 * Kept as a service (not inline in the migration) so the derivation is unit-
 * testable and can be re-run idempotently if a legacy row is ever written with
 * the generic pair missing.
 *
 * Precedence: PayPal is authoritative when a paypal_order_id is present (it can
 * never coexist with a Stripe id in this system); otherwise the row is a Stripe
 * one and the checkout session id is preferred over the payment-intent id.
 */
class DonationPaymentBackfillService
{
    public const METHOD_PAYPAL = 'paypal';

    public const METHOD_STRIPE = 'stripe';

    /**
     * Idempotent: only touches rows whose generic pair is absent or stale, so
     * running it against an already-backfilled table is a no-op.
     */
    public function run(): void
    {
        DB::table('donations')
            ->whereNotNull('paypal_order_id')
            ->where(function ($query) {
                $query->whereNull('payment_method')->orWhere('payment_method', '!=', self::METHOD_PAYPAL);
            })
            ->update([
                'payment_method' => self::METHOD_PAYPAL,
                'gateway_reference' => DB::raw('paypal_order_id'),
            ]);

        DB::table('donations')
            ->whereNull('paypal_order_id')
            ->where(function ($query) {
                $query->whereNotNull('stripe_session_id')->orWhereNotNull('stripe_payment_intent_id');
            })
            ->where(function ($query) {
                $query->whereNull('payment_method')->orWhere('payment_method', '!=', self::METHOD_STRIPE);
            })
            ->update([
                'payment_method' => self::METHOD_STRIPE,
                'gateway_reference' => DB::raw('COALESCE(stripe_session_id, stripe_payment_intent_id)'),
            ]);
    }
}
