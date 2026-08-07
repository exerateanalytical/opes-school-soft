<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00-core 14: the chain is only tamper-evident if something actually looks.
Schedule::command('opes:audit:verify')->dailyAt('02:30');

