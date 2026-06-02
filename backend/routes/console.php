<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --max-time=3599 --tries=3 --timeout=120')->hourly();
// Schedule::command('paypal:scrape-charities')->dailyAt('1:00');
