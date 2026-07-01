# Cron Setup for Wiki Donate

This project uses Laravel's scheduler for all recurring tasks. You only need **one** cron entry on the server.

## The Cron Entry

Add this single line to your crontab (cPanel → Cron Jobs):

```
* * * * * cd /home/<user>/<project-path>/backend && php artisan schedule:run >> /dev/null 2>&1
```

That's it. Laravel's scheduler handles everything else based on `routes/console.php`.

### For cPanel:

1. Go to **cPanel → Cron Jobs**
2. Under **Common Settings**, choose "Once Per Minute" (`* * * * *`)
3. In the **Command** field, enter:
   ```
   cd /home/yourusername/wikidonate/backend && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
4. Click **Add New Cron Job**

> **Note:** The correct path to PHP is often `/usr/local/bin/php` on cPanel servers. Use the full path — sometimes `/usr/bin/php` or just `php` depending on the server.

## Schedule Overview

| Task | Frequency | Description |
|------|-----------|-------------|
| `queue:work --stop-when-empty --max-time=1800` | Every minute | Processes queued jobs (donations, emails, notifications) then exits. Safety cap of 30 min per run. Mutex expires after 90 min to prevent stuck locks. |
| `queue:prune-failed --hours=48` | Daily | Removes failed job records older than 48 hours |
| `sanctum:prune-expired` | Daily | Cleans up expired API tokens |
| `paypal:scrape-charities` | Daily at 3 AM | *(commented out — uncomment when ready)* Pre-warms PayPal charity cache |

## What Changed (Previous vs. New)

The old schedule had:
- `queue:work --max-time=3550` running **hourly** with `withoutOverlapping()`

This was unreliable — if a worker hung or took longer than 59 minutes, the next cycle would be skipped, eventually halting queue processing entirely.

The new schedule:
- Uses `queue:work --stop-when-empty` **every minute** — processes everything in the queue, exits cleanly. Much more reliable for shared hosting.
- Adds daily cleanup tasks that weren't there before.
- Sends queue worker output to `storage/logs/queue-worker.log` for debugging.

## Verifying It Works

Check the queue worker log:

```bash
tail -f /home/yourusername/wikidonate/backend/storage/logs/queue-worker.log
```

Or check Laravel's main log:

```bash
tail -f /home/yourusername/wikidonate/backend/storage/logs/laravel.log
```

## Troubleshooting

### "Queue worker stopped processing jobs"
- Check the queue worker log: `storage/logs/queue-worker.log`
- Run manually to test: `php artisan queue:work --stop-when-empty --tries=3`
- Check the `jobs` table for stuck jobs
- If a job is stuck as "reserved" for too long, run: `php artisan queue:clear` (in dev only)

### "Schedule isn't running at all"
- Verify the cron job exists: `crontab -l`
- Verify PHP path on the server is correct
- Check Laravel log for scheduler errors
- Run manually to test: `php artisan schedule:run`

### "withoutOverlapping is preventing my task from running"
Tasks using `withoutOverlapping()` check if a previous instance is still running (via a mutex cache key). If the previous one is still going, the new one is skipped — this prevents duplicate processing.

The queue worker now uses `withoutOverlapping(90)` so the lock expires after 90 minutes instead of the default 1440. This means if a worker crashes without releasing its lock, the next run will succeed within 90 minutes instead of waiting 24 hours.
