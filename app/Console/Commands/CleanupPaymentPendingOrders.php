<?php

namespace App\Console\Commands;

use App\Models\PaymentPendingOrder;
use Illuminate\Console\Command;

/**
 * Remove abandoned in-flight checkout payloads older than the given threshold.
 *
 * Renamed from CleanupPayPalPendingOrders in phase 3 of the pluggable
 * payment-method refactor (the table is now `payment_pending_orders`); the
 * legacy command name remains available as an alias during the transition.
 */
class CleanupPaymentPendingOrders extends Command
{
    /** How long a closed (declined/cancelled) checkout stays queryable. */
    private const CLOSED_RETENTION_DAYS = 30;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:cleanup-pending-orders {--hours=48 : Delete pending orders older than this many hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete abandoned payment pending orders older than the given threshold';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        // Open checkouts are abandoned payloads: nothing will ever read them
        // again once the gateway order expires.
        $deleted = PaymentPendingOrder::where('status', PaymentPendingOrder::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->delete();

        // Closed checkouts (declined/cancelled/expired) are answers for the
        // donor's return page and a small audit trail, so they are kept much
        // longer than the gateway's retry window.
        $pruned = PaymentPendingOrder::whereIn('status', PaymentPendingOrder::terminalStatuses())
            ->where('updated_at', '<', now()->subDays(self::CLOSED_RETENTION_DAYS))
            ->delete();

        $this->info("Deleted {$deleted} abandoned payment pending order(s) older than {$hours} hours.");
        $this->info("Pruned {$pruned} closed checkout(s) older than ".self::CLOSED_RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
