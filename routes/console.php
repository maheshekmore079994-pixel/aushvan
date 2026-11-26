<?php

use Illuminate\Support\Facades\Schedule;

// Hosted installs: a daily verified backup, kept under storage/app/backups (retention in config/shop.php)
// and downloadable from Settings → Backup. Needs the server's cron to run `php artisan schedule:run`.
Schedule::command('backup:run --if-due')->dailyAt('21:30')->withoutOverlapping();
