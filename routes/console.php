<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00-core 14: the chain is only tamper-evident if something actually looks.
Schedule::command('opes:audit:verify')->dailyAt('02:30');

// 02-accounting 4.3, backstop column: the invariants are only guaranteed if
// something re-asserts them nightly. After the audit verify (a broken chain
// makes this run's own audit trail suspect) and before the backup verify, so
// tonight's backup captures a ledger this sweep has just pronounced on.
Schedule::command('opes:ledger:verify')->dailyAt('02:45');

// 08-operations 3.3-3.6. Ordered so each step has something to work on: take
// the backup, verify a bounded number of them, then prune, then - monthly -
// prove the newest one actually restores.
Schedule::command('opes:backup:run')->dailyAt('01:00');
Schedule::command('opes:backup:verify')->dailyAt('03:00');
Schedule::command('opes:backup:prune')->dailyAt('03:30');
Schedule::command('opes:backup:drill')->monthlyOn(1, '04:00');

// Queue heartbeat: writes the cache key QueueHeartbeatCheck reads. Without it a
// dead worker is invisible, and the backups it runs simply stop happening.
Schedule::call(static function (): void {
    Cache::put('opes.queue.heartbeat', now()->toIso8601String(), 3600);
})->everyFiveMinutes()->name('opes-queue-heartbeat');

