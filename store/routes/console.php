<?php

use Illuminate\Support\Facades\Schedule;

// Hostinger: hPanel → Advanced → Cron Jobs → every minute: php /home/USER/madeforu/artisan schedule:run
// Imports started from Admin → Import.
Schedule::command('store:import-queued')->everyMinute()->withoutOverlapping(60);
Schedule::command('store:prune-uploads')->daily();
Schedule::command('store:indexnow')->dailyAt('03:15');
Schedule::command('store:delhivery-sync')->hourly();
Schedule::command('store:cart-reminders')->everyFifteenMinutes();
Schedule::command('store:review-requests')->hourly();
// Evening summary for the shop, at the time set in Admin → Settings.
Schedule::command('store:daily-summary')->dailyAt((function () {
    try {
        $t = (string) setting('notify.daily_summary_time', '21:00');
    } catch (Throwable) {
        $t = '21:00';
    }

    return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t) ? $t : '21:00';
})());
