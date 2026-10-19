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

        $deleted = PaymentPendingOrder::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} abandoned payment pending order(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
