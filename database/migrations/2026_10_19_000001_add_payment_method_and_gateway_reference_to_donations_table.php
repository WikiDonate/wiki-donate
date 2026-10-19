<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 of the pluggable payment-method refactor: introduce the generic
     * `payment_method` + `gateway_reference` columns alongside the legacy
     * PayPal/Stripe id columns.
     *
     * The legacy columns stay the write source of truth until phase 3, but from
     * here on both are written (see Donation::saving mirror). This lets reports
     * and the UI switch to the generic fields while a rollback is still possible.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('donations', 'payment_method')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->string('payment_method', 50)->nullable()->after('donation_formula_id');
                $table->string('gateway_reference')->nullable()->after('payment_method');
            });
        }

        $this->backfill();

        // Composite index (non-unique in phase 1: legacy cols may still hold
        // duplicates during the transition; the unique index lands in phase 3).
        Schema::table('donations', function (Blueprint $table) {
            $table->index(['payment_method', 'gateway_reference'], 'donations_payment_method_reference_index');
        });
    }

    /**
     * Derive the generic columns for pre-existing rows.
     *
     * PayPal is authoritative when a paypal_order_id is present; otherwise the
     * row is a Stripe one and the session id is preferred over the intent id.
     */
    protected function backfill(): void
    {
        DB::table('donations')
            ->whereNotNull('paypal_order_id')
            ->where(function ($query) {
                $query->whereNull('payment_method')->orWhere('payment_method', '!=', 'paypal');
            })
            ->update([
                'payment_method' => 'paypal',
                'gateway_reference' => DB::raw('paypal_order_id'),
            ]);

        DB::table('donations')
            ->whereNull('paypal_order_id')
            ->where(function ($query) {
                $query->whereNotNull('stripe_session_id')->orWhereNotNull('stripe_payment_intent_id');
            })
            ->where(function ($query) {
                $query->whereNull('payment_method')->orWhere('payment_method', '!=', 'stripe');
            })
            ->update([
                'payment_method' => 'stripe',
                'gateway_reference' => DB::raw('COALESCE(stripe_session_id, stripe_payment_intent_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex('donations_payment_method_reference_index');
            $table->dropColumn(['payment_method', 'gateway_reference']);
        });
    }
};
