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


// 06-assets-stores 10.4/10.5 - the library's nightly discipline, in order:
// promote open loans past due to the PERSISTED `overdue` state, then recompute
// every overdue fine to its full entitlement (idempotent - running twice, or
// catching up after a missed week, lands on the same figure), then lapse
// reservations nobody collected. Unattended: Actor::system(), no Gate.
Schedule::call(static function (): void {
    $today = now()->toDateString();
    $system = \App\Support\Audit\Actor::system();

    app(\App\Modules\Library\Actions\PromoteOverdueIssues::class)->handle($today, $system);
    app(\App\Modules\Library\Actions\AccrueOverdueFines::class)->handle($today, $system);
    app(\App\Modules\Library\Actions\ExpireReservations::class)->handle($today, $system);
})->dailyAt('01:45')->name('opes-library-nightly');


// 08-operations 11.1 - drain the outbox. Safe at any frequency: DispatchOutbox
// claims each row under lockForUpdate and spends the attempt BEFORE calling the
// driver, so an overlapping run cannot double-send and a poison message cannot
// loop forever. withoutOverlapping is belt-and-braces, not the safety mechanism.
Schedule::command('opes:outbox:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('opes-outbox-dispatch');

// A held draft ("hold this admission, attend to someone else") that nobody
// returns to for an hour surfaces as a notification. Safe to overlap in
// principle (each run only reads/writes rows it owns), withoutOverlapping
// kept anyway for the same belt-and-braces reason as the outbox above.
Schedule::command('opes:forms:sweep-unfinished-work')
    ->hourly()
    ->withoutOverlapping()
    ->name('opes-forms-sweep-unfinished-work');

// Webhook deliveries: claim-then-spend under lock (DeliverPendingWebhooks),
// so overlapping runs cannot double-send and a permanently-erroring
// endpoint cannot loop forever - it exhausts after MAX_ATTEMPTS.
Schedule::command('opes:webhooks:deliver')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('opes-webhooks-deliver');
