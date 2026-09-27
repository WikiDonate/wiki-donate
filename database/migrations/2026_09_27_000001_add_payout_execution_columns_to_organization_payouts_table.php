<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfills columns the payouts feature needs on databases created
     * before the organization_payouts table definition was extended
     * (registry link, frozen destination, PayPal provider tracking).
     * All columns are nullable so existing rows are untouched.
     */
    public function up(): void
    {
        Schema::table('organization_payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_payouts', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            }
            if (! Schema::hasColumn('organization_payouts', 'destination_paypal_email')) {
                $table->string('destination_paypal_email')->nullable();
            }
            if (! Schema::hasColumn('organization_payouts', 'payout_batch_id')) {
                $table->string('payout_batch_id')->nullable();
            }
            if (! Schema::hasColumn('organization_payouts', 'payout_item_id')) {
                $table->string('payout_item_id')->nullable();
            }
            if (! Schema::hasColumn('organization_payouts', 'provider_status')) {
                $table->string('provider_status')->nullable()->comment('PayPal item transaction_status');
            }
            if (! Schema::hasColumn('organization_payouts', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }
            if (! Schema::hasColumn('organization_payouts', 'uuid')) {
                $table->uuid('uuid')->unique()->first();
            }
        });

        if (! Schema::hasIndex('organization_payouts', 'organization_payouts_payout_item_id_index')) {
            Schema::table('organization_payouts', function (Blueprint $table) {
                $table->index('payout_item_id');
            });
        }

        // Legacy tables defined paid_at as NOT NULL without a default, which
        // rejects pending rows (paid only when PayPal confirms). Raw ALTER:
        // doctrine/dbal is not installed so change() is unavailable. MySQL
        // only — fresh schemas (sqlite tests) already define it nullable.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `organization_payouts` MODIFY `paid_at` TIMESTAMP NULL DEFAULT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('organization_payouts', 'organization_payouts_payout_item_id_index')) {
            Schema::table('organization_payouts', function (Blueprint $table) {
                $table->dropIndex(['payout_item_id']);
            });
        }

        Schema::table('organization_payouts', function (Blueprint $table) {
            // Drop FK before its column (MySQL refuses otherwise).
            $table->dropForeign(['organization_id']);
            foreach (['failure_reason', 'provider_status', 'payout_item_id', 'payout_batch_id', 'destination_paypal_email', 'organization_id'] as $column) {
                if (Schema::hasColumn('organization_payouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
