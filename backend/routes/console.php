<?php

use Illuminate\Support\Facades\Schedule;

// ── Queue Processing ────────────────────────────────────────────────────────
// Runs every minute, processes all available jobs, then exits cleanly.
// For cPanel shared hosting where long-running daemons aren't possible.
// --max-time=1800 is a safety cap if jobs keep being dispatched.
// The mutex expires after 90 minutes to prevent stuck locks from blocking.
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120 --max-time=1800')
    ->everyMinute()
    ->withoutOverlapping(90)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// ── Housekeeping ────────────────────────────────────────────────────────────
// Clean up old failed jobs (keep last 48 hours)
Schedule::command('queue:prune-failed --hours=48')->daily();
// Remove expired Sanctum API tokens
Schedule::command('sanctum:prune-expired')->daily();

// ── Application Tasks ───────────────────────────────────────────────────────
// Pre-warm PayPal charities cache once daily at low-traffic time
// Uncomment below when you need PayPal cache pre-warming:
// Schedule::command('paypal:scrape-charities')->dailyAt('03:00');
