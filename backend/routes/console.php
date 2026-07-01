<?php

use Illuminate\Support\Facades\Schedule;

// Processes queued jobs then exits cleanly. --max-time prevents a single run
// from lingering indefinitely if jobs keep getting dispatched in a loop.
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120 --max-time=1800')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));
Schedule::command('queue:prune-failed --hours=48')->daily();
Schedule::command('sanctum:prune-expired')->daily();
