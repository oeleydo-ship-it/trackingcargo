<?php

use App\Jobs\PollCarrierTrackingJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PollCarrierTrackingJob)->everyFiveMinutes()->name('poll-carrier-tracking');

// Keeps the platform-wide failed_jobs table from growing forever; 30 days
// is comfortably longer than the 5-attempt/~26-minute webhook retry window
// (see SendWebhookJob), so nothing prunes before it's had a chance to be
// investigated or retried from Settings -> Failed jobs.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily()->name('prune-failed-jobs');
