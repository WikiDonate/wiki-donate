<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clear invented fees on payouts that were never provider-confirmed.
     *
     * Rows created under the old forced-5% model carry a fee that PayPal never
     * reported. While such a row is still `pending`, the real fee is unknown —
     * the next sync/webhook fills it from `payout_item_fee`. Paid legacy rows
     * keep their recorded numbers as history.
     */
    public function up(): void
    {
        DB::table('organization_payouts')
            ->where('fee_source', 'legacy_fixed')
            ->where('status', 'pending')
            ->update([
                'fee_amount' => null,
                'fee_percent' => null,
                'net_amount' => null,
                'fee_source' => 'pending_provider',
            ]);
    }

    public function down(): void
    {
        // Not reversible: the invented fee values cannot be reconstructed.
    }
};
