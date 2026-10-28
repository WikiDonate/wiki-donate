<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 of the pluggable payment-method refactor: make the generic
     * `payment_method` + `gateway_reference` pair the only identity columns.
     *
     *  - rename `paypal_pending_orders` -> `payment_pending_orders` and replace
     *    its PayPal-specific `paypal_order_id` with the generic pair;
     *  - rename `organization_payouts.method` -> `payment_method`;
     *  - drop the legacy `donations` id columns
     *    (paypal_order_id / stripe_session_id / stripe_payment_intent_id) —
     *    extra Stripe ids live in `metadata` from here on;
     *  - enforce uniqueness of (payment_method, gateway_reference).
     *
     * All steps are guarded so the migration is safe to run against a database
     * that has already been partially migrated (e.g. a rolled-back deploy).
     */
    public function up(): void
    {
        $this->rebuildPendingOrdersTable();
        $this->renamePayoutMethodColumn();
        $this->dropLegacyDonationColumns();
        $this->addDonationIdentityIndex();
    }

    private function rebuildPendingOrdersTable(): void
    {
        if (! Schema::hasTable('paypal_pending_orders') || Schema::hasTable('payment_pending_orders')) {
            return;
        }

        Schema::create('payment_pending_orders', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method', 50)->default('paypal');
            $table->string('gateway_reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('donation_formula_id')->nullable()->constrained('donation_formulas')->restrictOnDelete();
            $table->string('donor_email')->nullable();
            $table->string('donor_name')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('details')->nullable();
            $table->timestamps();

            $table->unique(['payment_method', 'gateway_reference'], 'payment_pending_orders_identity_unique');
            $table->index('donor_email');
        });

        DB::statement(
            'INSERT INTO payment_pending_orders '
            .'(id, payment_method, gateway_reference, user_id, donation_formula_id, donor_email, donor_name, amount, currency, details, created_at, updated_at) '
            ."SELECT id, 'paypal', paypal_order_id, user_id, donation_formula_id, donor_email, donor_name, amount, currency, details, created_at, updated_at "
            .'FROM paypal_pending_orders'
        );

        Schema::drop('paypal_pending_orders');
    }

    private function renamePayoutMethodColumn(): void
    {
        if (! Schema::hasTable('organization_payouts')) {
            return;
        }

        if (Schema::hasColumn('organization_payouts', 'method') && ! Schema::hasColumn('organization_payouts', 'payment_method')) {
            Schema::table('organization_payouts', function (Blueprint $table) {
                $table->renameColumn('method', 'payment_method');
            });
        }
    }

    private function dropLegacyDonationColumns(): void
    {
        if (! Schema::hasTable('donations')) {
            return;
        }

        // Historic unique indexes on the legacy columns must go before the
        // columns themselves (MySQL keeps them tied to the column).
        foreach ([
            'donations_stripe_session_id_unique',
            'donations_stripe_session_id_index',
            'donations_paypal_order_id_unique',
            'donations_payment_method_reference_index',
        ] as $index) {
            if (Schema::hasIndex('donations', $index)) {
                Schema::table('donations', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        $legacy = array_values(array_filter(
            ['paypal_order_id', 'stripe_session_id', 'stripe_payment_intent_id'],
            fn ($column) => Schema::hasColumn('donations', $column)
        ));

        if ($legacy !== []) {
            Schema::table('donations', function (Blueprint $table) use ($legacy) {
                $table->dropColumn($legacy);
            });
        }
    }

    private function addDonationIdentityIndex(): void
    {
        if (! Schema::hasTable('donations') || ! Schema::hasColumn('donations', 'payment_method')) {
            return;
        }

        if (! Schema::hasIndex('donations', 'donations_method_reference_unique')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->unique(['payment_method', 'gateway_reference'], 'donations_method_reference_unique');
            });
        }
    }

    public function down(): void
    {
        // Re-introduce the legacy columns/indexes for rollback, then move the
        // pending-order + payout columns back to their PayPal-era names.
        if (Schema::hasTable('donations')) {
            if (Schema::hasIndex('donations', 'donations_method_reference_unique')) {
                Schema::table('donations', function (Blueprint $table) {
                    $table->dropIndex('donations_method_reference_unique');
                });
            }

            Schema::table('donations', function (Blueprint $table) {
                if (! Schema::hasColumn('donations', 'paypal_order_id')) {
                    $table->string('paypal_order_id')->nullable()->unique();
                }
                if (! Schema::hasColumn('donations', 'stripe_session_id')) {
                    $table->string('stripe_session_id')->nullable()->unique();
                }
                if (! Schema::hasColumn('donations', 'stripe_payment_intent_id')) {
                    $table->string('stripe_payment_intent_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('organization_payouts') && Schema::hasColumn('organization_payouts', 'payment_method') && ! Schema::hasColumn('organization_payouts', 'method')) {
            Schema::table('organization_payouts', function (Blueprint $table) {
                $table->renameColumn('payment_method', 'method');
            });
        }

        if (Schema::hasTable('payment_pending_orders') && ! Schema::hasTable('paypal_pending_orders')) {
            Schema::create('paypal_pending_orders', function (Blueprint $table) {
                $table->id();
                $table->string('paypal_order_id')->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('donation_formula_id')->nullable()->constrained('donation_formulas')->restrictOnDelete();
                $table->string('donor_email')->nullable();
                $table->string('donor_name')->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('currency', 3)->default('USD');
                $table->text('details')->nullable();
                $table->timestamps();

                $table->index('donor_email');
            });

            DB::statement(
                'INSERT INTO paypal_pending_orders '
                .'(id, paypal_order_id, user_id, donation_formula_id, donor_email, donor_name, amount, currency, details, created_at, updated_at) '
                .'SELECT id, gateway_reference, user_id, donation_formula_id, donor_email, donor_name, amount, currency, details, created_at, updated_at '
                ."FROM payment_pending_orders WHERE payment_method = 'paypal'"
            );

            Schema::drop('payment_pending_orders');
        }
    }
};
