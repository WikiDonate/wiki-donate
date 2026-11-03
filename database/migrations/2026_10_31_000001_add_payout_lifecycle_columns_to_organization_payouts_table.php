<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout lifecycle hardening for the append-only ledger.
     *
     * Adds the columns the reconciliation paths need:
     *   - submitted_at  : when the provider transfer was attempted (NULL when
     *                     the row was never submitted, e.g. the request died
     *                     on the connection). Lets a scheduled job fail safely
     *                     rows that can never be reconciled.
     *   - attempt_count : how many times we tried to submit, for observability.
     *   - reversed_at   : when PayPal reported a post-processing reversal
     *                     (RETURNED / CANCELED / UNCLAIMED). Re-opens the
     *                     balance without editing the original row's amounts.
     */
    public function up(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_payouts', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('organization_payouts', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('submitted_at');
            }
            if (! Schema::hasColumn('organization_payouts', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('attempt_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            foreach (['reversed_at', 'attempt_count', 'submitted_at'] as $column) {
                if (Schema::hasColumn('organization_payouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
