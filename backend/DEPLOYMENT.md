# Deployment Guide — Scheduling & Cron (cPanel)

## One-Time Setup: cPanel Cron Job

The entire Laravel scheduling system is driven by **one** cron job that runs every minute.

### Step 1: Add the Cron Job

In cPanel → **Cron Jobs** → add:

```
* * * * * php /home/<username>/<path>/artisan schedule:run >> /dev/null 2>&1
```

Replace `<username>/<path>` with the actual path to the project (e.g. `wikidonate/wiki-donate/backend`).

**That's it.** The Laravel scheduler handles everything from here — queue work, cleanup, daily tasks.

> ⚠️ Remove any existing cron jobs that call `queue:work` or other artisan commands directly. The scheduler replaces them all.

## What the Scheduler Does

Defined in `routes/console.php`:

| When | What | Why |
|---|---|---|
| Every minute | `queue:work --stop-when-empty` | Processes queued jobs (donation confirmations, email notifications, etc.) |
| Daily at midnight | `queue:prune-failed --hours=48` | Cleans up failed job records older than 48 hours |
| Daily at midnight | `sanctum:prune-expired` | Removes expired API tokens from the database |
| Daily at midnight | `session:prune --hours=72` | Removes session records older than 72 hours |
| Daily at 03:00 (commented) | `paypal:scrape-charities` | Pre-warms PayPal charity cache — uncomment when needed |

## How Queue Workers Work on cPanel

Since cPanel doesn't support long-running daemon processes, the scheduler runs `queue:work --stop-when-empty` **every minute**:

1. It picks up any pending jobs from the `jobs` table
2. Processes them (up to 3 retries, 120 second timeout each)
3. Exits cleanly when the queue is empty
4. Next minute, the scheduler runs it again

This means:
- No jobs sit in the queue for more than ~60 seconds
- No daemon process to maintain
- If the cron process crashes, it restarts next minute automatically

## Verifying Everything Works

```bash
# Check scheduler is running (lock files)
ls -la storage/framework/cache/*.lock

# Check queue worker logs
tail -f storage/logs/queue-worker.log

# Manually test the scheduler (dry run)
php artisan schedule:run --no-interaction -v

# Check for any stuck locks
php artisan schedule:list
```
