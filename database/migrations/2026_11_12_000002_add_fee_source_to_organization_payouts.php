<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout fees are reported by the provider, not computed from a fixed rate.
     *
     * `fee_source` records where fee_amount came from:
     *   pending_provider — row created, provider has not reported the fee yet
     *   paypal           — actual payout_item_fee / batch fees from PayPal
     *   legacy_fixed     — rows created under the old forced 5% deduction
     */
    public function up(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_payouts', 'fee_source')) {
                $table->string('fee_source')->nullable()->after('net_amount');
            }
        });

        DB::table('organization_payouts')
            ->whereNotNull('fee_amount')
            ->whereNull('fee_source')
            ->update(['fee_source' => 'legacy_fixed']);
    }

    public function down(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            $table->dropColumn('fee_source');
        });
    }
};
