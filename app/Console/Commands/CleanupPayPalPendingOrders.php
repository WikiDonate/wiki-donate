<?php

namespace App\Console\Commands;

/**
 * @deprecated Phase 3 renamed this command to CleanupPaymentPendingOrders
 * (signature `payments:cleanup-pending-orders`). This alias keeps the old
 * signature working for existing crons/tests during the transition window.
 */
class CleanupPayPalPendingOrders extends CleanupPaymentPendingOrders
{
    /**
     * @var string
     */
    protected $signature = 'paypal:cleanup-pending-orders {--hours=48 : Delete pending orders older than this many hours}';

    /**
     * @var string
     */
    protected $description = 'Delete abandoned payment pending orders older than the given threshold (legacy alias)';
}
