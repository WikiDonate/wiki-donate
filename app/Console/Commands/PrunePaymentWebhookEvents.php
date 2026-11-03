<?php

namespace App\Console\Commands;

use App\Models\PaymentWebhookEvent;
use Illuminate\Console\Command;

/**
 * Trim the payment webhook log.
 *
 * Only terminal, successful deliveries are pruned. `failed` rows are kept by
 * default because they are the evidence trail for a payment that did not
 * settle; pass --include-failed only if you have exported them elsewhere.
 */
class PrunePaymentWebhookEvents extends Command
{
    protected $signature = 'webhooks:prune
        {--days=90 : Retain terminal deliveries newer than this many days}
        {--include-failed : Also delete failed deliveries (off by default)}';

    protected $description = 'Delete old processed payment webhook events (failed rows are kept)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $statuses = [PaymentWebhookEvent::STATUS_PROCESSED, PaymentWebhookEvent::STATUS_IGNORED];

        if ($this->option('include-failed')) {
            $statuses[] = PaymentWebhookEvent::STATUS_FAILED;
        }

        $deleted = PaymentWebhookEvent::query()
            ->whereIn('status', $statuses)
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d payment webhook event(s) older than %d day(s).',
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
