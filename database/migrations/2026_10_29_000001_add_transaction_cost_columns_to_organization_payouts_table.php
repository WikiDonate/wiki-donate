<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the enforced transaction-cost breakdown to the payout ledger.
     *
     * Every new payout row stores:
     *   - fee_percent : the rate (e.g. 5.00) applied at execution time
     *   - fee_amount  : gross × rate, rounded half-up to cents
     *   - net_amount  : gross − fee — the amount actually transferred
     *
     * `amount` stays the GROSS figure (it is what consumes the organization's
     * owed balance) and the ledger stays append-only.
     *
     * LEGACY BACKFILL DECISION (documented, applied consistently to reports):
     * rows created before this migration were paid gross — no fee was ever
     * deducted — so they are backfilled with fee_percent = 0, fee_amount = 0,
     * net_amount = amount. Reporting code can therefore always assert
     * `amount = fee_amount + net_amount` without special-casing a null era.
     */
    public function up(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_payouts', 'fee_percent')) {
                $table->decimal('fee_percent', 5, 2)->nullable()
                    ->comment('Transaction-cost rate applied at execution time (e.g. 5.00)');
            }
            if (! Schema::hasColumn('organization_payouts', 'fee_amount')) {
                $table->decimal('fee_amount', 12, 2)->nullable()
                    ->comment('Transaction cost deducted from the gross amount');
            }
            if (! Schema::hasColumn('organization_payouts', 'net_amount')) {
                $table->decimal('net_amount', 12, 2)->nullable()
                    ->comment('Amount actually transferred to the organization (gross - fee)');
            }
        });

        // Backfill pre-fee rows: paid gross, nothing deducted.
        DB::table('organization_payouts')
            ->whereNull('net_amount')
            ->update([
                'fee_percent' => 0,
                'fee_amount' => 0,
                'net_amount' => DB::raw('amount'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            foreach (['net_amount', 'fee_amount', 'fee_percent'] as $column) {
                if (Schema::hasColumn('organization_payouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
