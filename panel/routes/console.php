<?php

use Illuminate\Support\Facades\Schedule;

// Run by `php artisan schedule:run`, which /etc/cron.d/johnny-panel calls every minute.

// Scheduled syncs can repeat as often as every minute.
Schedule::command('johnny:syncs:run')->everyMinute()->withoutOverlapping(10);

// Keys that reach all buckets also get the buckets created outside the panel (CLI, S3 clients).
Schedule::command('johnny:keys:apply')->everyFiveMinutes()->withoutOverlapping(10);
