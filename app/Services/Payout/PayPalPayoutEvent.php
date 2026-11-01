<?php

namespace App\Services\Payout;

/**
 * Normalizes PayPal Payouts webhook event names.
 *
 * PayPal has shipped two naming families for the same notifications:
 *
 *   Legacy (still what the Payouts product emits in the dashboard):
 *     PAYMENT.PAYOUTSBATCH.SUCCESS / .DENIED / .PROCESSING / .PENDING
 *     PAYMENT.PAYOUTS-ITEM.SUCCEEDED / .FAILED / .BLOCKED / .RETURNED
 *                                       / .CANCELED / .HELD / .UNCLAIMED
 *
 *   Newer / docs sample:
 *     PAYOUTS.BATCH.PROCESSING.COMPLETED
 *     PAYOUTS.ITEM.SUCCEEDED / .COMPLETED / .DENIED / ...
 *
 * The app must react to whichever family the configured webhook is subscribed
 * to, so every event is reduced to a canonical `batch:<status>` or
 * `item:<status>` key here, and the controllers match on that.
 */
final class PayPalPayoutEvent
{
    public const KIND_BATCH = 'batch';

    public const KIND_ITEM = 'item';

    /**
     * Explicit aliases for event names whose suffix is not the final status
     * token (e.g. BATCH.PROCESSING.COMPLETED ends in COMPLETED, not SUCCESS).
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'PAYMENT.PAYOUTSBATCH.PROCESSING' => 'batch:PROCESSING',
        'PAYMENT.PAYOUTSBATCH.PENDING' => 'batch:PENDING',
        'PAYMENT.PAYOUTSBATCH.SUCCESS' => 'batch:SUCCESS',
        'PAYMENT.PAYOUTSBATCH.DENIED' => 'batch:DENIED',
        'PAYMENT.PAYOUTSBATCH.FAILED' => 'batch:FAILED',
        'PAYMENT.PAYOUTSBATCH.CANCELED' => 'batch:CANCELED',
        'PAYMENT.PAYOUTS-ITEM.SUCCEEDED' => 'item:SUCCESS',
        'PAYMENT.PAYOUTS-ITEM.COMPLETED' => 'item:SUCCESS',
        'PAYMENT.PAYOUTS-ITEM.FAILED' => 'item:FAILED',
        'PAYMENT.PAYOUTS-ITEM.DENIED' => 'item:DENIED',
        'PAYMENT.PAYOUTS-ITEM.BLOCKED' => 'item:BLOCKED',
        'PAYMENT.PAYOUTS-ITEM.RETURNED' => 'item:RETURNED',
        'PAYMENT.PAYOUTS-ITEM.CANCELED' => 'item:CANCELED',
        'PAYMENT.PAYOUTS-ITEM.HELD' => 'item:HELD',
        'PAYMENT.PAYOUTS-ITEM.UNCLAIMED' => 'item:UNCLAIMED',
        'PAYOUTS.BATCH.PROCESSING.COMPLETED' => 'batch:SUCCESS',
        'PAYOUTS.BATCH.PROCESSING.DENIED' => 'batch:DENIED',
        'PAYOUTS.BATCH.PROCESSING.PENDING' => 'batch:PENDING',
        'PAYOUTS.ITEM.COMPLETED' => 'item:SUCCESS',
        'PAYOUTS.ITEM.SUCCEEDED' => 'item:SUCCESS',
    ];

    /**
     * Statuses that mean "the money did not (or no longer) reach the org".
     *
     * @return array<int, string>
     */
    public const FAILURE_STATUSES = ['FAILED', 'DENIED', 'BLOCKED'];

    /**
     * Statuses that mean "the transfer was reversed after processing" — the
     * funds come back and the balance must be re-opened.
     *
     * PayPal's item `transaction_status` enum is:
     * SUCCESS | FAILED | PENDING | UNCLAIMED | RETURNED | ONHOLD | BLOCKED |
     * REFUNDED | REVERSED. `CANCELED` is a *batch* status (kept here for the
     * legacy event family), while `REFUNDED`/`REVERSED` are real item
     * reversals that used to be ignored.
     *
     * @return array<int, string>
     */
    public const REVERSAL_STATUSES = [
        'RETURNED',
        'CANCELED',
        'UNCLAIMED',
        'REFUNDED',
        'REVERSED',
    ];

    /**
     * Reduce a raw PayPal event_type to `batch:<STATUS>` / `item:<STATUS>`,
     * or null when the event is not a payout event we handle.
     */
    public static function normalize(?string $eventType): ?string
    {
        $event = strtoupper(trim((string) $eventType));

        if ($event === '') {
            return null;
        }

        if (isset(self::ALIASES[$event])) {
            return self::ALIASES[$event];
        }

        // Unknown-name fallback: infer the kind from the prefix and use the
        // trailing token as the status, so a newly-added PayPal status still
        // routes to the right handler instead of being dropped.
        if (str_starts_with($event, 'PAYMENT.PAYOUTSBATCH') || str_starts_with($event, 'PAYOUTS.BATCH')) {
            $status = self::trailingToken($event);
            $status = $status === 'COMPLETED' ? 'SUCCESS' : $status;

            return $status === '' ? null : self::KIND_BATCH.':'.$status;
        }

        if (str_starts_with($event, 'PAYMENT.PAYOUTS-ITEM') || str_starts_with($event, 'PAYOUTS.ITEM')) {
            $status = self::trailingToken($event);
            $status = in_array($status, ['SUCCEEDED', 'COMPLETED'], true) ? 'SUCCESS' : $status;

            return $status === '' ? null : self::KIND_ITEM.':'.$status;
        }

        return null;
    }

    /**
     * Canonical status out of a normalized key (`item:SUCCESS` → `SUCCESS`).
     */
    public static function status(?string $normalized): string
    {
        if (! $normalized) {
            return '';
        }

        $parts = explode(':', $normalized, 2);

        return strtoupper($parts[1] ?? '');
    }

    /**
     * Whether a canonical status is a definitive failure.
     */
    public static function isFailure(string $status): bool
    {
        return in_array(strtoupper($status), self::FAILURE_STATUSES, true);
    }

    /**
     * Whether a canonical status is a post-processing reversal.
     */
    public static function isReversal(string $status): bool
    {
        return in_array(strtoupper($status), self::REVERSAL_STATUSES, true);
    }

    private static function trailingToken(string $event): string
    {
        $parts = explode('.', $event);

        return (string) end($parts);
    }
}
