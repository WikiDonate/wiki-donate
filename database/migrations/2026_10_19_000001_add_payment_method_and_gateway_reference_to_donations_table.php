<?php

use App\Services\Payment\DonationPaymentBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (! Schema::hasIndex('donations', 'donations_payment_method_reference_index')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->index(['payment_method', 'gateway_reference'], 'donations_payment_method_reference_index');
            });
        }
    }

    /**
     * Derive the generic columns for pre-existing rows.
     *
     * Delegates to DonationPaymentBackfillService so the derivation logic is
     * unit-testable and independently re-runnable (see that class for the
     * PayPal-beats-Stripe precedence rule).
     */
    protected function backfill(): void
    {
        app(DonationPaymentBackfillService::class)->run();
    }

    public function down(): void
    {
        if (Schema::hasIndex('donations', 'donations_payment_method_reference_index')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->dropIndex('donations_payment_method_reference_index');
            });
        }

        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'gateway_reference']);
        });
    }
};
