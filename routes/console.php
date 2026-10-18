<?php

use Illuminate\Support\Facades\Schedule;

// Hostinger: hPanel → Advanced → Cron Jobs → every minute: php /home/USER/madeforu/artisan schedule:run
Schedule::command('store:prune-uploads')->daily();
Schedule::command('store:indexnow')->dailyAt('03:15');
