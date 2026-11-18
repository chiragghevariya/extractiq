<?php

use Illuminate\Support\Facades\Schedule;

// Email ingestion: poll the inbound mailbox (Mailpit locally, IMAP in production) every minute.
Schedule::command('extractiq:poll-inbox')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// ERP: pull vendors, POs, receipts and bill payment status (pushed → posted → paid) every 15 minutes.
Schedule::command('extractiq:erp-sync')->everyFifteenMinutes()->withoutOverlapping();

// Analytics: rebuild the daily rollup for the last 30 days (today is always computed live).
Schedule::command('extractiq:analytics-rollup --days=30')->dailyAt('01:15')->withoutOverlapping();

// Public demo: wipe and re-seed the demo tenant every night (only when DEMO_MODE=true).
Schedule::command('extractiq:demo-reset')->dailyAt((string) config('extractiq.demo.reset_at', '03:30'))
    ->when(fn () => (bool) config('extractiq.demo.enabled'))->withoutOverlapping()->onOneServer();

// Uptime: the health endpoint reports the scheduler stale if this heartbeat stops.
Schedule::call(fn () => Illuminate\Support\Facades\Cache::forever('extractiq:scheduler-heartbeat', now()->toIso8601String()))->name('scheduler-heartbeat')->everyMinute();
