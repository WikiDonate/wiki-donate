<?php

namespace App\Console\Commands;

use App\Services\Payout\OrganizationPayoutService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcile pending organization payouts with the provider.
 *
 * Payouts are submitted synchronously, but PayPal settles asynchronously:
 * a transfer can stay `pending` for minutes before the item is confirmed.
 * Without a poller the balance stays reserved until an admin presses
 * "Sync statuses", so this command runs the same reconciliation on a
 * schedule and releases rows that never reached the provider.
 */
class SyncOrganizationPayouts extends Command
{
    protected $signature = 'payouts:sync
        {--recheck-paid : Also re-poll recently paid rows for a late PayPal reversal}';

    protected $description = 'Reconcile pending organization payouts with the payment provider';

    public function handle(OrganizationPayoutService $payouts): int
    {
        $recheckPaid = (bool) $this->option('recheck-paid');

        try {
            $result = $payouts->syncPending($recheckPaid);
        } catch (Throwable $e) {
            $this->error('Payout sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $changed = $result['changed']->count();

        $this->info(sprintf(
            'Checked %d payout(s)%s: %d updated, %d error(s).',
            $result['checked'],
            $recheckPaid ? ' (including recently paid)' : '',
            $changed,
            $result['failed']
        ));

        foreach ($result['changed'] as $payout) {
            $this->line(sprintf(
                '  #%d %s → %s (%s)',
                $payout->id,
                $payout->organization_name,
                $payout->status,
                strtoupper($payout->currency)
            ));
        }

        return self::SUCCESS;
    }
}
