<?php

use Illuminate\Support\Facades\Schedule;

// Running when the next cron tick fires (lock expires after default 24h).
Schedule::command('queue:work --tries=3 --timeout=120 --sleep=3 --max-time=3550')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// Clean up old failed jobs (keep last 48 hours)
Schedule::command('queue:prune-failed --hours=48')->daily();
// Remove expired Sanctum API tokens
Schedule::command('sanctum:prune-expired')->daily();
// Remove abandoned PayPal pending orders (48h TTL)
Schedule::command('paypal:cleanup-pending-orders --hours=48')->daily();
