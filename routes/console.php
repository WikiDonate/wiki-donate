<?php

use Illuminate\Support\Facades\Schedule;

// Clean up old failed jobs (keep last 48 hours)
Schedule::command('queue:prune-failed --hours=48')->daily();
// Remove expired Sanctum API tokens
Schedule::command('sanctum:prune-expired')->daily();
// Remove abandoned PayPal pending orders (48h TTL)
Schedule::command('paypal:cleanup-pending-orders --hours=48')->daily();
