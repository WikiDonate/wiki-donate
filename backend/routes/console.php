<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --max-time=3599')->hourly();
Schedule::command('paypal:scrape-charities')->dailyAt('1:00');
