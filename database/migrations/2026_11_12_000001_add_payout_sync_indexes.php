<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the payout reconciliation and balance aggregates.
     *
     * `syncPending()` filters by (status, payout_batch_id), and the balance
     * math sums payouts/donations per formula with a status filter. Without
     * these the sync/allocations queries are full table scans.
     */
    public function up(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            $table->index(['status', 'payout_batch_id'], 'org_payouts_status_batch_index');
            $table->index(['donation_formula_id', 'status'], 'org_payouts_formula_status_index');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->index(['donation_formula_id', 'status'], 'donations_formula_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            $table->dropIndex('org_payouts_status_batch_index');
            $table->dropIndex('org_payouts_formula_status_index');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex('donations_formula_status_index');
        });
    }
};
