<?php

use Illuminate\Support\Facades\Schedule;

// ── Queue Processing ────────────────────────────────────────────────────────
// The server cron fires once per hour (cPanel limitation).
// --max-time=3550 keeps the worker alive for ~59 minutes between ticks,
// polling every 3 seconds so new jobs are picked up within seconds.
// withoutOverlapping() prevents a duplicate if the previous worker is still
// running when the next cron tick fires (lock expires after default 24h).
Schedule::command('queue:work --tries=3 --timeout=120 --sleep=3 --max-time=3550')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// ── Housekeeping ────────────────────────────────────────────────────────────
// Clean up old failed jobs (keep last 48 hours)
Schedule::command('queue:prune-failed --hours=48')->daily();
// Remove expired Sanctum API tokens
Schedule::command('sanctum:prune-expired')->daily();
