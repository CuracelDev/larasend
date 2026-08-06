<?php

use App\Jobs\PruneExpiredEmailData;
use App\Jobs\RecheckPendingDomains;
use App\Jobs\RecoverStuckQueuedEmails;
use App\Jobs\SyncCloudflareSuppressions;
use App\Jobs\SyncStaleSourceQuotas;
use App\Support\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(SystemHealth::class)->recordSchedulerHeartbeat())
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->onOneServer();
Schedule::job(new RecoverStuckQueuedEmails)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::job(new SyncCloudflareSuppressions)->hourly()->withoutOverlapping()->onOneServer();
Schedule::job(new RecheckPendingDomains)->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::job(new SyncStaleSourceQuotas)->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::job(new PruneExpiredEmailData)->dailyAt('02:30')->withoutOverlapping()->onOneServer();
