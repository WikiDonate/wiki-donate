<?php

use Illuminate\Support\Facades\Schedule;

// Clean up old failed jobs (keep last 48 hours)
Schedule::command('queue:prune-failed --hours=48')->daily();
// Remove expired Sanctum API tokens
Schedule::command('sanctum:prune-expired')->daily();
// Remove abandoned payment pending orders (48h TTL)
Schedule::command('payments:cleanup-pending-orders --hours=48')->daily();
// Reconcile pending organization payouts (PayPal settles asynchronously).
// Without this, a pending payout keeps its balance reserved until an admin
// presses "Sync statuses"; it also re-submits rows the provider never
// acknowledged (with the same dedupe id) instead of releasing them.
Schedule::command('payouts:sync')->everyFifteenMinutes()->withoutOverlapping();
// Deep pass: re-poll recently paid rows so a late RETURNED/REFUNDED reversal
// is caught even when the PayPal webhook is never delivered.
Schedule::command('payouts:sync --recheck-paid')->daily()->withoutOverlapping();
