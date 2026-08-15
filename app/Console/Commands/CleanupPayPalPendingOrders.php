<?php

namespace App\Console\Commands;

use App\Models\PayPalPendingOrder;
use Illuminate\Console\Command;

class CleanupPayPalPendingOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paypal:cleanup-pending-orders {--hours=48 : Delete pending orders older than this many hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete abandoned PayPal pending orders older than the given threshold';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $deleted = PayPalPendingOrder::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} abandoned PayPal pending order(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
