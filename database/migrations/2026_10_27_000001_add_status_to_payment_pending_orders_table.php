<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give in-flight checkouts a lifecycle instead of deleting them.
     *
     * A pending order used to be deleted the moment PayPal denied/cancelled
     * the payment, which made the donor's reference "unknown" — the return
     * page then rendered the generic fallback ("payment successful"). Keeping
     * the row with a terminal status lets the read-only status endpoint answer
     * failed/cancelled honestly, and leaves an audit trail for admin reports.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('payment_pending_orders', 'status')) {
            Schema::table('payment_pending_orders', function (Blueprint $table) {
                $table->string('status', 20)->default('pending')->after('currency')->index();
                $table->string('status_reason', 255)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_pending_orders', 'status')) {
            Schema::table('payment_pending_orders', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropColumn(['status', 'status_reason']);
            });
        }
    }
};
