<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organization payout configuration cleanup.
     *
     * 1. `payout_methods` — the payout rails an organization can be paid with,
     *    as a JSON array of `payment_methods.code` values (default ['paypal']).
     *    PayPal stays the implicit default so every pre-existing destination
     *    keeps working without a data migration on the payout rail.
     *
     * 2. Drop the legacy `paypal_confirm_token` / `paypal_confirm_sent_at`
     *    columns. Verification moved to an explicit admin action (verify /
     *    unverify) — the token-based email round-trip was retired with that
     *    decision, so the columns are dead weight (and a second, unused
     *    verification authority we do not want).
     *
     * Both halves are guarded so the migration is safe to re-run against a
     * database that already saw a partial rollout.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'payout_methods')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->json('payout_methods')->nullable()->after('paypal_email');
            });
        }

        // Existing destinations keep their historical PayPal rail.
        DB::table('organizations')
            ->whereNull('payout_methods')
            ->update(['payout_methods' => json_encode(['paypal'])]);

        $this->dropColumnIfExists('paypal_confirm_token');
        $this->dropColumnIfExists('paypal_confirm_sent_at');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'paypal_confirm_token') === false) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('paypal_confirm_token', 64)->nullable()->unique();
            });
        }

        if (Schema::hasColumn('organizations', 'paypal_confirm_sent_at') === false) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->timestamp('paypal_confirm_sent_at')->nullable();
            });
        }

        if (Schema::hasColumn('organizations', 'payout_methods')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('payout_methods');
            });
        }
    }

    /**
     * Drop a column, first releasing any unique index that references it.
     *
     * MySQL removes a column's own index implicitly, but SQLite refuses to
     * drop an indexed column, so the index is dropped explicitly (ignoring
     * "already gone" errors) before the column itself.
     */
    private function dropColumnIfExists(string $column): void
    {
        if (! Schema::hasColumn('organizations', $column)) {
            return;
        }

        try {
            Schema::table('organizations', function (Blueprint $table) use ($column) {
                $table->dropUnique("organizations_{$column}_unique");
            });
        } catch (Throwable $e) {
            // Index already absent — nothing to release.
        }

        Schema::table('organizations', function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }
};
